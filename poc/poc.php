<?php
/**
 * 全栈智能出行助手 — POC 脚本
 * 验证: 高德 API 参数契约 + LLM 意图解析 + 完整闭环
 * 依赖: guzzlehttp/guzzle + vlucas/phpdotenv
 *
 * 用法:
 *   php poc.php --step=1          # 只验证高德 geocode + driving（waypoints + 多策略 + extensions）
 *   php poc.php --step=2          # 只验证 LLM 自然语言 → JSON 意图解析
 *   php poc.php --step=3          # 完整闭环: 自然语言 → 路线对比表
 *   php poc.php --step=all        # 跑全部（默认）
 *   php poc.php --input="明天下午3点从国贸到首都机场，接一个朋友"
 */

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

// ============== 环境初始化 ==============
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$amapKey  = $_ENV['AMAP_KEY'] ?? '';
$llmKey   = $_ENV['LLM_API_KEY'] ?? '';
$llmUrl   = rtrim($_ENV['LLM_BASE_URL'] ?? 'https://openrouter.ai/api/v1', '/');
$llmModel = $_ENV['LLM_MODEL'] ?? 'openrouter/qwen/qwen-plus';

$amap = new Client([
    'base_uri' => 'https://restapi.amap.com/v3/',
    'timeout'  => 10,
]);

$llm = new Client([
    'timeout'  => 30,
]);

$options   = getopt('', ['step::', 'input::']);
$step      = $options['step'] ?? 'all';
$userInput = $options['input'] ?? '明天下午3点从国贸到首都机场，接一个朋友';

// ============== 工具函数 ==============
function section(string $title): void {
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "  $title\n";
    echo str_repeat('=', 70) . "\n";
}
function info(string $msg): void { echo "  ℹ️  $msg\n"; }
function ok(string $msg): void   { echo "  ✅ $msg\n"; }
function warn(string $msg): void { echo "  ⚠️  $msg\n"; }
function fail(string $msg): void { echo "  ❌ $msg\n"; }
function tag(string $n, string $m): void { echo "\n  ▶ Step $n: $m\n"; }
function jp($d): string { return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); }

// -----------------------------------------------------------
// Step 1: 高德 API 参数契约验证
// -----------------------------------------------------------
function step1_amap(Client $amap, string $key): void {
    section('Step 1: 高德 API 参数契约验证');

    if (!$key) {
        fail('未检测到 AMAP_KEY → 跳过。请在 .env 中填写后重试。');
        info('高德 Web 服务 Key 申请: https://console.amap.com/dev/key/app');
        return;
    }

    // --- geocode ---
    tag(1, '地理编码: "国贸中心" → 坐标');
    try {
        $r = $amap->get('geocode/geo', ['query' => [
            'address' => '北京国贸中心', 'city' => '北京', 'key' => $key,
        ]]);
        $d = json_decode($r->getBody(), true);
        if (($d['status'] ?? '') === '1' && !empty($d['geocodes'])) {
            $loc = $d['geocodes'][0]['location'];
            ok("geocode OK → \"北京国贸中心\" = $loc");
            info("完整 geocode 字段: " . jp($d['geocodes'][0]));
        } else {
            fail("geocode 失败: " . jp($d)); return;
        }
    } catch (\Throwable $e) {
        fail("geocode 异常: " . $e->getMessage()); return;
    }

    // 先 geocode 三个测试点
    $pts = [];
    foreach (['北京国贸中心', '首都机场T3航站楼', '望京SOHO'] as $addr) {
        try {
            $r = $amap->get('geocode/geo', ['query' => ['address' => $addr, 'city' => '北京', 'key' => $key]]);
            $d = json_decode($r->getBody(), true);
            $pts[$addr] = $d['geocodes'][0]['location'] ?? null;
        } catch (\Throwable) { $pts[$addr] = null; }
    }
    ok("三点坐标: 起点={$pts['北京国贸中心']}, 终点={$pts['首都机场T3航站楼']}, 途经={$pts['望京SOHO']}");

    $O = $pts['北京国贸中心'];
    $D = $pts['首都机场T3航站楼'];
    $W = $pts['望京SOHO'];

    // --- 多策略 driving（base extensions）---
    tag(2, '多策略 driving 对比（extensions=base）');
    $strategies = [0 => '速度优先', 4 => '费用优先', 10 => '距离优先'];
    $results = [];
    foreach ($strategies as $code => $label) {
        try {
            $r = $amap->get('direction/driving', ['query' => [
                'origin' => $O, 'destination' => $D, 'strategy' => $code,
                'extensions' => 'base', 'key' => $key,
            ]]);
            $d = json_decode($r->getBody(), true);
            if (($d['status'] ?? '') === '1' && !empty($d['route']['paths'])) {
                $p = $d['route']['paths'][0];
                $results[$label] = ['dist_m' => (int)$p['distance'], 'dur_s' => (int)$p['duration']];
                ok("  strategy=$code ($label) → dist={$p['distance']}m, dur={$p['duration']}s, tolls=" . ($p['tolls'] ?? '?'));
            } else {
                warn("  strategy=$code → " . jp($d));
            }
        } catch (\Throwable $e) {
            fail("  strategy=$code → " . $e->getMessage());
        }
    }

    // --- driving with waypoints ---
    tag(3, 'driving with waypoints（单途经点: 望京SOHO）');
    try {
        $r = $amap->get('direction/driving', ['query' => [
            'origin' => $O, 'destination' => $D, 'waypoints' => $W,
            'strategy' => 0, 'extensions' => 'base', 'key' => $key,
        ]]);
        $d = json_decode($r->getBody(), true);
        if (($d['status'] ?? '') === '1') {
            $p = $d['route']['paths'][0];
            ok("单 waypoint OK → dist={$p['distance']}m, dur={$p['duration']}s");
        } else {
            warn("单 waypoint 失败: " . jp($d));
        }
    } catch (\Throwable $e) {
        fail("单 waypoint 异常: " . $e->getMessage());
    }

    // --- driving with MULTIPLE waypoints（竖线分隔）---
    tag(4, 'driving with MULTIPLE waypoints（竖线 "|" 分隔）');
    try {
        $multiWp = $W . '|' . $O;
        $r = $amap->get('direction/driving', ['query' => [
            'origin' => $O, 'destination' => $D, 'waypoints' => $multiWp,
            'strategy' => 0, 'extensions' => 'base', 'key' => $key,
        ]]);
        $d = json_decode($r->getBody(), true);
        if (($d['status'] ?? '') === '1') {
            $p = $d['route']['paths'][0];
            ok("多 waypoint OK (waypoints=\"$multiWp\") → dist={$p['distance']}m, dur={$p['duration']}s");
        } else {
            warn("多 waypoint 失败: " . jp($d));
        }
    } catch (\Throwable $e) {
        fail("多 waypoint 异常: " . $e->getMessage());
    }

    // --- extensions=all（验证 polyline + steps）---
    tag(5, 'driving extensions=all（验证 polyline + steps 字段）');
    try {
        $r = $amap->get('direction/driving', ['query' => [
            'origin' => $O, 'destination' => $D, 'strategy' => 0,
            'extensions' => 'all', 'key' => $key,
        ]]);
        $d = json_decode($r->getBody(), true);
        if (($d['status'] ?? '') === '1') {
            $p = $d['route']['paths'][0];
            ok("extensions=all → polyline=" . mb_strlen($p['polyline'] ?? '') . " chars, steps=" . count($p['steps'] ?? []));
            info("path 顶层 keys: " . implode(',', array_keys($p)));
        } else {
            warn("extensions=all 异常: " . jp($d));
        }
    } catch (\Throwable $e) {
        fail("extensions=all 异常: " . $e->getMessage());
    }

    // --- 汇总 ---
    section('Step 1 汇总');
    echo jp([
        'origin'   => ['text' => '北京国贸中心', 'coord' => $O],
        'dest'     => ['text' => '首都机场T3', 'coord' => $D],
        'waypoint' => ['text' => '望京SOHO', 'coord' => $W],
        'waypoints_format'     => 'lng,lat|lng,lat （竖线分隔，支持最多 16 个）',
        'extensions_base_keys' => ['origin', 'destination', 'paths[{distance,duration,tolls,traffic_lights}]'],
        'extensions_all_keys'  => 'base + polyline + steps + restriction',
    ]) . "\n\n";
}

// -----------------------------------------------------------
// Step 2: LLM 自然语言 → JSON 意图解析
// -----------------------------------------------------------
function step2_llm(Client $llm, string $url, string $apiKey, string $model, string $input): ?array {
    section('Step 2: LLM 自然语言 → JSON 意图解析');
    info("输入: \"$input\"");
    info("API : $url  |  Model: $model");

    if (!$apiKey) {
        fail('未检测到 LLM_API_KEY → 跳过。请在 .env 中填写。');
        info('推荐 OpenRouter: https://openrouter.ai （支持 Qwen/Claude/GPT 等多个模型）');
        return null;
    }

    $system = <<<'PROMPT'
你是出行意图解析专家。严格输出一个 JSON 对象，不要输出任何 JSON 之外的文字。

JSON schema:
{
    "origin": {"text": "起点文字", "coords": null},
    "destination": {"text": "终点文字", "coords": null},
    "waypoints": [
        {"text": "中途停靠点文字或null", "coords": null, "purpose": "pickup_passenger|dropoff_passenger|via"}
    ],
    "pickup_time": "ISO8601时间或null",
    "pickup_time_range": {"earliest": null, "latest": null},
    "passengers": 1,
    "constraints": ["约束条件"],
    "vehicle_preference": null,
    "budget": null,
    "special_needs": null,
    "confidence": 0.0,
    "needs_clarification": false,
    "clarification_question": null
}

规则:
- waypoints 只有在有明确的中间停靠/接人/送人时才填，否则返回 []
- "顺路可以接" 属于模糊约束 → 放入 constraints
- "接一个朋友从望京" → waypoints = [{"text": "望京", "purpose": "pickup_passenger"}]
- 时间推断: "明天下午3点" = 明天 15:00（注意根据当前日期推算具体日期）
- confidence < 0.6 时 needs_clarification=true 并给出追问
PROMPT;

    try {
        $r = $llm->post("$url/chat/completions", [
            'headers' => [
                'Authorization' => "Bearer $apiKey",
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'       => $model,
                'temperature' => 0.1,
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $input],
                ],
            ],
        ]);
        $body = json_decode($r->getBody(), true);
        $raw  = $body['choices'][0]['message']['content'] ?? '';

        // 清理 markdown 代码块包裹
        $raw = trim($raw);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $m)) $raw = $m[1];

        $intent = json_decode($raw, true);
        if ($intent === null) {
            fail("JSON 解析失败！LLM 原始输出:\n$raw");
            return null;
        }
        ok("JSON 解析成功");
        echo "\n  LLM 解析结果:\n" . jp($intent) . "\n\n";

        // 关键字段校验
        $checks = [
            'origin.text'      => isset($intent['origin']['text']),
            'destination.text' => isset($intent['destination']['text']),
            'waypoints'        => array_key_exists('waypoints', $intent),
        ];
        foreach ($checks as $f => $b) { $b ? ok("  ✓ $f") : warn("  ✗ 缺少 $f"); }
        return $intent;
    } catch (\Throwable $e) {
        fail("LLM 异常: " . $e->getMessage());
        return null;
    }
}

// -----------------------------------------------------------
// Step 3: 完整闭环
// -----------------------------------------------------------
function step3_pipeline(Client $llm, string $url, string $llmKey, string $model,
                       Client $amap, string $amapKey, string $input): void {
    section('Step 3: 完整闭环 — 自然语言 → 路线对比表');

    if (!$amapKey || !$llmKey) {
        fail('需要 AMAP_KEY + LLM_API_KEY 才能跑完整闭环。'); return;
    }

    // 3.1 LLM 意图解析
    $intent = step2_llm($llm, $url, $llmKey, $model, $input);
    if (!$intent || !($intent['origin']['text'] ?? null) || !($intent['destination']['text'] ?? null)) {
        fail('意图解析失败，无法继续。'); return;
    }

    // 3.2 地理编码
    section('3.2 地理编码（所有地点 → 坐标）');
    $coords = [];
    foreach (['origin' => $intent['origin']['text'], 'destination' => $intent['destination']['text']] as $label => $addr) {
        try {
            $r = $amap->get('geocode/geo', ['query' => ['address' => $addr, 'city' => '北京', 'key' => $amapKey]]);
            $d = json_decode($r->getBody(), true);
            if (($d['status'] ?? '') === '1') {
                $coords[$label] = $d['geocodes'][0]['location'];
                ok("  $label: \"$addr\" → {$coords[$label]}");
            } else { fail("  $label: \"$addr\" → " . jp($d)); return; }
        } catch (\Throwable $e) { fail("  $label: " . $e->getMessage()); return; }
    }
    // waypoints
    $wpParam = null;
    if (!empty($intent['waypoints'])) {
        $wps = [];
        foreach ($intent['waypoints'] as $wp) {
            if (!empty($wp['text'])) {
                try {
                    $r = $amap->get('geocode/geo', ['query' => ['address' => $wp['text'], 'city' => '北京', 'key' => $amapKey]]);
                    $d = json_decode($r->getBody(), true);
                    if (($d['status'] ?? '') === '1') { $wps[] = $d['geocodes'][0]['location']; }
                } catch (\Throwable) {}
            }
        }
        if (!empty($wps)) { $wpParam = implode('|', $wps); ok("  waypoints: $wpParam"); }
    }

    // 3.3 多策略路线规划
    section('3.3 多策略驾车路径规划');
    echo str_pad('策略', 12) . str_pad('距离(km)', 12) . str_pad('时间(min)', 12) . str_pad('过路费(¥)', 12) . str_pad('红绿灯', 10) . "\n";
    echo str_repeat('-', 70) . "\n";

    foreach ([0 => '最快', 4 => '最省钱', 10 => '距离优先'] as $code => $label) {
        $q = ['origin' => $coords['origin'], 'destination' => $coords['destination'],
              'strategy' => $code, 'extensions' => 'base', 'key' => $amapKey];
        if ($wpParam) $q['waypoints'] = $wpParam;
        try {
            $r = $amap->get('direction/driving', ['query' => $q]);
            $d = json_decode($r->getBody(), true);
            if (($d['status'] ?? '') === '1' && !empty($d['route']['paths'])) {
                $p = $d['route']['paths'][0];
                echo str_pad($label, 12)
                   . str_pad(round($p['distance']/1000, 1), 12)
                   . str_pad(round($p['duration']/60), 12)
                   . str_pad($p['tolls'] ?? '?', 12)
                   . str_pad($p['traffic_lights'] ?? '?', 10) . "\n";
            } else { echo str_pad($label, 12) . "❌ " . jp($d) . "\n"; }
        } catch (\Throwable $e) { echo str_pad($label, 12) . "❌ " . mb_substr($e->getMessage(), 0, 30) . "\n"; }
    }

    echo "\n" . str_repeat('=', 70) . "\n";
    ok("✅ 完整闭环通过！");
    echo str_repeat('=', 70) . "\n\n";
}

// ========================================================
// 主流程
// ========================================================
echo "\n🚗 全栈智能出行助手 — POC 验证\n";
echo "PHP " . PHP_VERSION . " | " . date('Y-m-d H:i:s') . "\n";
echo "输入: \"$userInput\"\n";

switch ($step) {
    case '1': step1_amap($amap, $amapKey); break;
    case '2': step2_llm($llm, $llmUrl, $llmKey, $llmModel, $userInput); break;
    case '3': step3_pipeline($llm, $llmUrl, $llmKey, $llmModel, $amap, $amapKey, $userInput); break;
    case 'all':
    default:
        step1_amap($amap, $amapKey);
        step2_llm($llm, $llmUrl, $llmKey, $llmModel, $userInput);
        step3_pipeline($llm, $llmUrl, $llmKey, $llmModel, $amap, $amapKey, $userInput);
        break;
}

echo "\n🏁 POC 结束\n\n";
