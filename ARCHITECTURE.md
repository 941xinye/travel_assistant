# 全栈智能出行助手 — 项目架构设计

> AI + GEO + Swoole 三合一智能出行平台
> 创建日期：2026-10-13

---

## 一、项目概述

### 1.1 业务愿景

用户通过**自然语言描述**出行需求（"明天下午3点从国贸到首都机场，要接一个朋友，顺路可以接"），AI 自动完成意图解析 → 地图路径规划 → 司机智能匹配 → 全程实时追踪 → 到达后自动结算费用的完整闭环。

### 1.2 核心价值主张

| 维度 | 传统出行应用 | 本项目方案 |
|------|-------------|-----------|
| 输入方式 | 表单填写起点终点 | 一句话自然语言 |
| 路线方案 | 单一路线 | 多策略对比（最快/省钱/风景） |
| 匹配机制 | 最近司机 | 地理围栏 + 顺路匹配 + AI评分 |
| 异常处理 | 手动申诉 | AI自动检测并改道 |
| 行程回顾 | 账单流水 | AI生成出行日记 |

### 1.3 关键技术组合

```
AI 层  →  Laravel AI SDK（LLM 推理） + 高德地图 MCP Server（地图工具）
GEO 层 →  PostGIS（空间计算） + 高德地图 API（路网/路径）
实时层 →  Swoole + Octane（高性能后端） + WebSocket（实时推送）
```

---

## 二、技术选型总览

### 2.1 整体技术栈矩阵

| 层级 | 技术 | 版本 | 选型理由 |
|------|------|------|---------|
| **后端框架** | Laravel + Octane | Laravel 11 / Octane 2.x | Swoole 协程支持，常驻内存，3ms 冷启动 |
| **实时服务** | Swoole WebSocket | Swoole 5.x | 原生协程 WebSocket，支持 10w+ 并发连接 |
| **LLM 集成** | Laravel AI SDK | 最新 | 统一的 AI 抽象层，支持多模型切换，内置 Agent 模式 |
| **地图能力** | 高德地图 MCP Server | — | MCP 协议暴露为 AI 可调用的 Tool，含 geocode/driving/geofence |
| **关系数据库** | MySQL | 8.0+ | 用户/订单/计费事务数据 |
| **缓存层** | Redis | 7.x | 司机实时位置、WebSocket 会话、热点数据缓存、地理空间索引 |
| **空间数据库** | PostGIS | PostgreSQL 16 + PostGIS 3 | 司机位置点、路线轨迹存储、空间查询（ST_DWithin 就近匹配） |
| **向量数据库** | pgvector | Postgres 扩展 | 用户历史出行向量、语义检索"常去哪儿""喜欢什么路线" |
| **Web 前端** | Next.js | 15 (App Router) | SSR/SSG，地图组件生态好，WebSocket 客户端封装成熟 |
| **地图组件** | 高德地图 JS API v2.0 | — | 高德官方 React 封装，支持 WebGL 2.0 大规模轨迹渲染 |
| **移动前端** | React Native | 0.74+ | 司机端 App，支持后台持续定位推送 |
| **消息队列** | Redis Streams / RabbitMQ | — | 解耦 WebSocket 推送与业务处理，削峰填谷 |
| **容器化** | Docker + Docker Compose | — | 本地开发与生产环境一致 |

### 2.2 为什么是 Laravel + Swoole Octane？

```
传统 PHP-FPM
├─ 每个请求启动进程 → 50ms 冷启动
├─ 进程生命周期内无状态 → 实时连接需额外中间件
└─ Nginx 负载均衡 → 会话粘性复杂

Laravel Octane + Swoole
├─ 常驻内存 → 首次启动后请求 < 3ms
├─ 协程调度 → 同一进程内处理 HTTP + WebSocket + Timer
├─ Laravel 生态完全兼容 → Eloquent / Queue / Cache 照常使用
└─ Swoole Table → 进程间共享数据（如在线司机列表）
```

### 2.3 集成路径决策（⚠️ POC 验证后最终敲定）

> 高德 MCP Server 虽然官方有（`https://mcp.amap.com/mcp?key=XXX`），但 Laravel AI SDK 是否原生支持 MCP 协议的 tool calling **未经验证**。
> 同时高德 MCP Server 暴露的 driving 工具只接受 origin/destination，**不支持 waypoints 和 strategy**，无法满足核心需求。
> 因此后端运行时的集成路径最终定为：

| 能力 | 集成路径 | 理由 |
|------|---------|------|
| **高德地图 API** | **REST API 直调**（`https://restapi.amap.com/v3/...`） | POC 已验证 geocode / driving(waypoints) / 多策略 全部可用；参数契约清晰；与框架解耦 |
| **LLM 调用** | **OpenAI-compatible HTTP 直调**（Guzzle + JSON） | POC 已验证 DeepSeek / OpenRouter 等均可跑通；无 SDK 版本锁定风险 |
| **Laravel AI SDK** | **暂不引入** | 当前阶段 REST + Guzzle 方案已覆盖全部需求；后续如需多 Agent 编排再评估 |
| **高德 MCP Server** | **仅作为 AI IDE 辅助开发工具**（Cursor / Cline 中配置） | 不进入后端运行时 |

### 2.4 多数据源分工（数据边界绝对清晰）

| 数据源 | 存储内容 | 写入时机 | 读取场景 |
|--------|---------|---------|---------|
| **MySQL** | users / drivers / trips / orders / payments | 事务提交（创建订单、支付结算） | 后台管理、账单查询、用户历史 |
| **PostGIS** | driver_positions / route_segments / trip_trajectories | 司机每秒上报位置、路线规划结果 | 空间查询（附近司机）、轨迹回放、偏离检测 |
| **Redis** | driver:online:{id} / trip:active:{id} / ws:session:{sid} | 司机上线、订单激活、WebSocket 连接 | 实时匹配、行程推送、在线状态 |
| **pgvector** | user_trip_embeddings / route_feature_vectors | 行程完成后异步向量化 | "找一条和上次类似的路线"、个性化推荐 |

---

## 三、系统架构设计

### 3.1 分层架构图（文字版）

```
┌─────────────────────────────────────────────────────────────────┐
│                        客户端层                                  │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐   │
│  │ Next.js Web  │  │ React Native │  │   司机端 App         │   │
│  │ 乘客端(SSR)  │  │ 司机端(移动) │  │   (后台定位+推送)    │   │
│  └──────┬───────┘  └──────┬───────┘  └──────────┬───────────┘   │
└─────────┼─────────────────┼────────────────────┼─────────────────┘
          │ HTTPS           │ HTTPS/WSS           │ WSS
┌─────────┴─────────────────┴────────────────────┴─────────────────┐
│                       网关 & 实时层（⚠️ 双进程独立）               │
│  ┌──────────────────────────┐  ┌────────────────────────────┐    │
│  │  Swoole HTTP Server      │  │  Swoole WS Server (独立进程) │    │
│  │  (Octane 驱动 Laravel)   │  │  仅负责连接管理 + 消息推送   │    │
│  │  HTTP Worker ×4          │  │  WS Worker ×2               │    │
│  │  Timer Worker (异常检测)  │  │  不含 Laravel Container     │    │
│  └──────────┬───────────────┘  └──────────────┬───────────────┘    │
│             │                                  │                    │
│             │    Redis Pub/Sub 跨进程通信       │                    │
│             │  HTTP Worker → ws:trip:{id}      │                    │
│             └──────────────────────────────────┘                    │
└───────────────┬───────────────────────────────────────────────────┘
                │  （HTTP Worker 内运行完整 Laravel 应用）
┌───────────────┴───────────────────────────────────────────────────┐
│                       业务逻辑层（Laravel Container）              │
│                                                                   │
│  ┌────────────┐ ┌────────────┐ ┌────────────┐ ┌────────────┐     │
│  │ AI 意图    │ │ 路线规划   │ │ 司机匹配   │ │ 异常检测   │     │
│  │ 解析服务   │ │ 服务       │ │ 服务       │ │ 服务       │     │
│  └─────┬──────┘ └─────┬──────┘ └─────┬──────┘ └─────┬──────┘     │
│        │              │              │              │              │
│  ┌─────┴──────────────┴──────────────┴──────────────┴──────┐     │
│  │              Trip Orchestrator（行程编排器）              │     │
│  │    状态机: PENDING → MATCHED → ON_THE_WAY → ...        │     │
│  │    需要推送 → Redis::publish("ws:trip:{$id}", $msg)     │     │
│  └──────────────────────────┬───────────────────────────────┘     │
│                              │                                    │
│  ┌──────────────────────────┴───────────────────────────────┐     │
│  │              领域服务层                                   │     │
│  │  UserService | DriverService | BillingService | GeoFence │     │
│  └──────────────────────────────────────────────────────────┘     │
└───────────────────────────────────────────────────────────────────┘
                │                              │
┌───────────────┴──────────────────────────────┴─────────────────────┐
│                       AI & GEO 能力层                             │
│                                                                   │
│  ┌────────────────────────────┐  ┌────────────────────────────┐   │
│  │  Laravel AI SDK            │  │  高德地图 MCP Server       │   │
│  │  ├─ Intent Parser Agent   │  │  ├─ geocode (地址→坐标)    │   │
│  │  ├─ Route Advisor Agent   │  │  ├─ direction/driving       │   │
│  │  ├─ Trip Summarizer Agent │  │  ├─ geofence (地理围栏)    │   │
│  │  └─ Embedding Engine      │  │  └─ traffic (实时路况)     │   │
│  └────────────────────────────┘  └────────────────────────────┘   │
└───────────────────────────────────────────────────────────────────┘
                │                              │
┌───────────────┴──────────────────────────────┴─────────────────────┐
│                       数据存储层                                  │
│  ┌──────┐  ┌──────┐  ┌─────────┐  ┌──────────┐                   │
│  │MySQL │  │Redis │  │ PostGIS │  │ pgvector │                   │
│  │事务  │  │实时   │  │ 空间    │  │ 向量     │                   │
│  └──────┘  └──────┘  └─────────┘  └──────────┘                   │
└───────────────────────────────────────────────────────────────────┘
```

### 3.2 数据流图（核心链路）

```
用户输入自然语言
       │
       ▼
┌─────────────────────────────────────────────────────┐
│ 1. Intent Parser Agent (LLM)                         │
│    输入: "明天下午3点从国贸到首都机场，接一个朋友"       │
│    输出: {                                            │
│      "origin": "国贸",                                │
│      "destination": "首都机场",                        │
│      "pickup_time": "2026-10-14T15:00:00",            │
│      "passengers": 2,                                 │
│      "constraints": ["顺路可以接"],                   │
│      "intent_confidence": 0.92                        │
│    }                                                  │
└─────────────────────┬───────────────────────────────┘
                      │ 结构化 JSON
                      ▼
┌─────────────────────────────────────────────────────┐
│ 2. 地理编码 (MCP Server: geocode)                     │
│    "国贸" → {lng: 116.460, lat: 39.909}              │
│    "首都机场" → {lng: 116.606, lat: 40.079}           │
└─────────────────────┬───────────────────────────────┘
                      │ 坐标
                      ▼
┌─────────────────────────────────────────────────────┐
│ 3. 多策略路线规划 (MCP Server: driving)                │
│    返回: [最快路线, 最省钱路线, 风景路线]                │
│    每条: {distance, duration, cost, polyline, steps}  │
└─────────────────────┬───────────────────────────────┘
                      │ 路线方案
                      ▼
┌─────────────────────────────────────────────────────┐
│ 4. 司机匹配                                          │
│    a) PostGIS: ST_DWithin() 查 3km 内空闲司机         │
│    b) Redis: 实时在线状态过滤                          │
│    c) AI评分: 顺路度 + 接单率 + 用户偏好               │
└─────────────────────┬───────────────────────────────┘
                      │ 匹配结果
                      ▼
┌─────────────────────────────────────────────────────┐
│ 5. 行程编排器 (Trip Orchestrator)                     │
│    状态流转 → 推送通知 → 初始化 WebSocket 频道        │
└─────────────────────┬───────────────────────────────┘
                      │ 行程激活
                      ▼
┌─────────────────────────────────────────────────────┐
│ 6. 全程实时追踪 (WebSocket)                           │
│    司机每秒上报位置 → Redis + PostGIS 双写             │
│    Swoole Timer 定时检测 → 异常事件触发改道            │
└─────────────────────┬───────────────────────────────┘
                      │ 到达目的地
                      ▼
┌─────────────────────────────────────────────────────┐
│ 7. 自动结算 + AI 行程报告                              │
│    MySQL 写入账单 → pgvector 生成出行向量              │
│    Trip Summarizer Agent 生成出行日记                  │
└─────────────────────────────────────────────────────┘
```

---

## 四、6 大核心模块详细设计

### 模块 1：自然语言出行意图解析

**职责**：将用户的自然语言出行描述解析为结构化 JSON。

```php
// app/Services/AI/IntentParser.php

class IntentParser
{
    public function parse(string $userInput, User $user): TripIntent
    {
        $prompt = <<<'PROMPT'
你是出行意图解析专家。从用户的自然语言描述中提取出行需求。

当前日期: {{today}}
用户输入: "{{input}}"

请输出严格的 JSON 格式:
{
    "origin": {"text": "起点文字", "coords": null},
    "destination": {"text": "终点文字", "coords": null},
    "waypoints": [                              // 【新增】途经点（顺路接朋友等场景）
        {"text": "中途停靠点文字", "coords": null, "purpose": "pickup_passenger"},
        {"text": null, "coords": null, "purpose": "dropoff_passenger"}
    ],
    "pickup_time": "ISO8601 时间或 null（表示立即）",
    "pickup_time_range": {"earliest": null, "latest": null},
    "passengers": 1,
    "constraints": ["不要走高速", "避开拥堵"],
    "vehicle_preference": null,
    "budget": null,
    "special_needs": null,
    "confidence": 0.0,
    "needs_clarification": false,
    "clarification_question": null
}

规则:
- 时间推断: "明天下午3点" = 明天 15:00（⚠️ 必须基于 prompt 注入的当前日期计算，不能用 LLM 训练时的日期）
- 有明确的中间停靠/接人/送人需求 **且** 用户提供了具体地点 → 放入 waypoints（如"从望京接一个朋友" → waypoints[0]={text:"望京", purpose:"pickup_passenger"}）
- 有接人/送人需求但没给地点（如"接一个朋友"）→ **不要塞 special_needs**，而是 needs_clarification=true 并追问"朋友在哪个位置上车？"
- 纯模糊表达"顺路" → 放入 constraints，由 RoutePlanner 在路线搜索时宽松处理
- 置信度 < 0.6 时 needs_clarification=true 并给出追问
PROMPT;

        // ⚠️ 关键：运行时注入当前日期（避免 LLM 用训练数据瞎编日期）
        $today = now()->format('Y-m-d');
        $prompt = str_replace(['{{input}}', '{{today}}'], [$userInput, $today], $prompt);

        $response = AI::agent()
            ->withSystemPrompt($prompt)
            ->withResponseFormat('json')
            ->act('parse_trip_intent');

        return TripIntent::fromJson($response->json());
    }
}
```

**设计要点**：
- LLM 输出直接走 JSON Schema 约束（Laravel AI SDK 支持 `withResponseFormat`）
- 若 `confidence < 0.6`，自动进入多轮追问模式澄清需求
- 解析结果中 `coords` 字段初始为 null，由模块 2 地理编码填充
- 对常见别名做预处理："国贸" → "北京国贸中心"（从用户历史中补全城市）

**⚠️ LLM 降级策略（必加）**：
- **429 限流**：AI SDK 抛出 429 异常 → 降级为正则 + 地理编码 API 直出（"明天下午3点" → cron 解析器 + geocode → 简化 TripIntent），**禁止移除 LLM 主链路**
- **JSON 格式错误**：AI SDK 返回非 JSON → 自动 retry 1 次，仍失败则返回 `needs_clarification=true` 并提示"我没太听清，请再说一遍出发地和目的地"
- **超时**：10 秒超时 → 降级为最小 TripIntent（只提取 origin + destination，其他字段 null），引导用户补齐
- 限流/降级状态通过 `X-AI-Degraded: true` Header 传递给前端，前端展示"AI 暂时繁忙，已为您切换快速模式"提示

---

### 模块 2：智能路线规划

**职责**：基于多策略规划多条路线供用户选择。

```php
// app/Services/Geo/RoutePlanner.php

class RoutePlanner
{
    public function plan(TripIntent $intent, array $strategies = ['balanced', 'via_poi_a', 'via_poi_b']) : array
    {
        // Step 1: 地理编码（强制前置 — 高德 driving 只接受坐标）
        $coords = $this->geocodeAll($intent);

        // Step 2: 拼装 waypoints 参数（高德 driving 最多 16 个，格式 "lng,lat|lng,lat"）
        $userWaypointsParam = $this->buildWaypoints($coords['waypoints']);

        // Step 3: 多方案规划
        // ⚠️ POC 关键发现（三次实测）：
        //   - strategy 0/4/10 返回完全相同的路线（免费版不区分）
        //   - avoid_highway / avoid_jam 也不生效（POC 实测无差异）
        //   - **唯一能真正改变路线的手段 = waypoints 强制绕行**
        // 差异化方案靠：在基础 waypoints 上 + 额外不同的途经点来生成不同路线
        $routes = [];
        foreach ($strategies as $variant) {
            $params = [
                'origin'      => $coords['origin']['lng'] . ',' . $coords['origin']['lat'],
                'destination' => $coords['destination']['lng'] . ',' . $coords['destination']['lat'],
                'strategy'    => 10,     // 免费版统一用距离优先
                'extensions'  => 'all', // ⚠️ 必须 all 才能拿到 steps[].polyline
            ];

            // 根据变体加不同的 waypoints（强制绕行制造差异化）
            switch ($variant) {
                case 'via_poi_a':
                    // 绕道经过用户常去的 POI（从 pgvector 历史或周边搜索取）
                    $extraWp = $this->pickViaPoiA($coords);
                    $params['waypoints'] = $this->mergeWaypoints($userWaypointsParam, $extraWp);
                    $label = '途经热门点 A';
                    break;
                case 'via_poi_b':
                    // 绕道经过另一个 POI
                    $extraWp = $this->pickViaPoiB($coords);
                    $params['waypoints'] = $this->mergeWaypoints($userWaypointsParam, $extraWp);
                    $label = '途经热门点 B';
                    break;
                default: // balanced — 用户原始 waypoints，不额外加
                    if ($userWaypointsParam !== null) {
                        $params['waypoints'] = $userWaypointsParam;
                    }
                    $label = '推荐';
            }

            $route = $this->callAmap('direction_driving', $params);
            $paths = $route['route']['paths'] ?? [];

            $routes[] = [
                'variant'  => $variant,
                'label'    => $label,
                'distance' => (int)($paths[0]['distance'] ?? 0),
                'duration' => (int)($paths[0]['duration'] ?? 0),
                'cost'     => $this->estimateCost($paths[0] ?? [], $intent),
                'polyline' => $this->extractPolyline($paths[0] ?? []),
                'steps'    => $paths[0]['steps'] ?? [],
                'traffic_lights' => $paths[0]['traffic_lights'] ?? null,
                'tolls'    => $paths[0]['tolls'] ?? null,
                'toll_distance' => $paths[0]['toll_distance'] ?? null,
            ];
        }

        return $this->rankAndFilter($routes, $intent);
    }

    /** 合并用户 waypoints + 额外绕行 waypoints（竖线 "|" 分隔）*/
    private function mergeWaypoints(?string $user, string $extra): string
    {
        return $user ? "$user|$extra" : $extra;
    }

    /** 从 POI 搜索/用户历史中选绕行点 A（示例：途经望京商圈）*/
    private function pickViaPoiA(array $coords): string
    {
        // 实现时: 用高德 keywords/search API 搜索 "望京" / 用户常去 POI
        return '116.488095,39.990997'; // 望京 SOHO 坐标，占位
    }

    /** 从 POI 搜索/用户历史中选绕行点 B（示例：途经三元桥）*/
    private function pickViaPoiB(array $coords): string
    {
        return '116.467628,39.971755'; // 三元桥，占位
    }

    /**
     * ⚠️ POC 关键发现：高德 driving API path.polyline 顶层是空的！
     * polyline 分散在 steps[].polyline 里，格式是 "lng,lat;lng,lat;lng,lat"（分号分隔）
     * 需要遍历所有 step 拼接成完整坐标数组
     */
    private function extractPolyline(array $path): array
    {
        $steps = $path['steps'] ?? [];
        $coords = [];
        foreach ($steps as $step) {
            $stepPoly = $step['polyline'] ?? '';
            if (empty($stepPoly)) continue;
            foreach (explode(';', $stepPoly) as $point) {
                $parts = explode(',', $point);
                if (count($parts) === 2) {
                    $coords[] = ['lng' => (float)$parts[0], 'lat' => (float)$parts[1]];
                }
            }
        }
        return [
            'available' => !empty($coords),
            'points'    => $coords,
            'count'     => count($coords),
        ];
    }

    private function buildWaypoints(array $waypointsCoords): ?string
    {
        if (empty($waypointsCoords)) return null;
        return collect($waypointsCoords)
            ->map(fn($w) => "{$w['lng']},{$w['lat']}")
            ->implode('|');
    }

    private function geocodeAll(TripIntent $intent): array
    {
        $result = [
            'origin'      => $this->geocodeOne($intent->origin->text),
            'destination' => $this->geocodeOne($intent->destination->text),
            'waypoints'   => [],
        ];
        foreach ($intent->waypoints as $wp) {
            if ($wp->text) {
                $result['waypoints'][] = $this->geocodeOne($wp->text);
            }
        }
        return $result;
    }
}
```

**设计要点**：
- **地址→坐标强制前置**：高德 driving API 只接受 `lng,lat` 格式，必须先 geocode（之前踩过 `INVALID_PARAMS` 坑）
- **⚠️ polyline 提取方式（POC 实测）**：高德 v3 driving 的 `path.polyline` 顶层为空，polyline 分散在 `steps[].polyline` 里，格式是 `"lng,lat;lng,lat;lng,lat"`（分号分隔）。前端渲染需遍历所有 step 拼接成完整坐标数组（17 个 step 拼接后 ~7570 chars）
- **⚠️ extensions 必须设为 all**：否则 steps 里连 polyline 都没有
- **⚠️ 多方案差异化（POC 三次实测修正）**：
  - strategy 0/4/10 → 完全相同路线
  - avoid_highway / avoid_jam=1 → 也不生效
  - **唯一有效手段 = waypoints 强制绕行**（竖线分隔）
  - 方案差异化靠：基础方案 vs 绕道望京 vs 绕道三元桥（或从 pgvector/POI 搜索取用户偏好的绕行点）
  - 前端展示为：「推荐」「途经热门点」「XXX 方案」
- **缓存**：相同起终点 + 变体的路线 10 分钟内走 Redis 缓存

---

### 模块 3：实时司机匹配

**职责**：根据位置、状态、顺路度匹配最优司机。

```php
// app/Services/Driver/Matcher.php

class Matcher
{
    public function match(TripIntent $intent, RoutePlan $route): ?Driver
    {
        // Step 1: PostGIS 空间查询 — 3km 半径内空闲司机
        $nearbyDrivers = DB::select("
            SELECT d.*, ST_Distance(dp.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)) AS distance_meters
            FROM drivers d
            JOIN driver_positions dp ON d.id = dp.driver_id
            WHERE d.status = 'online_idle'
              AND ST_DWithin(dp.location, ST_SetSRID(ST_MakePoint(?, ?), 4326), 3000)
            ORDER BY distance_meters ASC
            LIMIT 20
        ", [$intent->origin->lng, $intent->origin->lat, $intent->origin->lng, $intent->origin->lat]);

        // Step 2: Redis 实时状态过滤（排除 5 秒未上报位置的司机）
        $candidates = array_filter($nearbyDrivers, fn($d) => Redis::exists("driver:online:{$d->id}"));

        // Step 3: AI 评分（顺路度 + 历史 + 用户偏好）
        $scored = collect($candidates)
            ->map(fn($d) => [
                'driver'  => $d,
                'score'   => $this->score($d, $route, $intent),
            ])
            ->sortByDesc('score')
            ->take(3)
            ->values();

        return $scored->first()?->driver;
    }

    private function score($driver, RoutePlan $route, TripIntent $intent): float
    {
        return 0.4 * $this->detourScore($driver, $route)   // 顺路度（偏离当前去向）
             + 0.3 * $this->distanceScore($driver, $intent) // 距接客点距离
             + 0.2 * $this->ratingScore($driver)            // 用户评分
             + 0.1 * $this->preferenceScore($driver, $intent); // 用户偏好（如车型）
    }
}
```

**设计要点**：
- **地理围栏主动推送**：司机上线时 Redis `GEOADD` 写入 `drivers:geo`，匹配时用 `GEORADIUS` 做快速初筛，PostGIS 做精确计算
- **顺路度算法**：取司机当前位置 → 司机当前目的地 → 用户起点 → 用户终点，计算合并路线与原路线的偏离率
- **匹配原子性**：匹配成功后 Redis `SET driver:online:{id} 'matched' EX 30`（30秒超时保护，防止重复匹配）

---

### 模块 4：全程轨迹追踪

**职责**：WebSocket 实时推送司机位置到乘客端，并存储轨迹。

> **⚠️ 进程边界关键**：WS Server 是**独立原生 Swoole 进程**，**不加载 Laravel Container**。
> 所以 WS Worker 里**不能用** `Redis::` / `DB::` / `now()` 等 Laravel facade。
> 司机位置持久化走 **Redis Streams → Octane HTTP Worker 消费** 管道。

```php
// bin/ws-server.php  —— 这是原生 Swoole 脚本，不是 Laravel Controller/Handler

$server = new Swoole\WebSocket\Server("0.0.0.0", 8081);
$server->set(['worker_num' => 2]);

// 每个 WS Worker 内部维护自己的 Redis 连接 + 频道订阅
$server->on("workerStart", function ($server, $workerId) {
    $GLOBALS['redis'] = new Swoole\Coroutine\Redis();
    $GLOBALS['redis']->connect("127.0.0.1", 6379);
});

// 司机 / 乘客 WebSocket 连接进来
$server->on("open", function ($server, $request) {
    $token = parse_url($request->get['token'] ?? '', PHP_URL_QUERY);
    // JWT 鉴权（纯原生实现，不依赖 Laravel Auth）
    if (!JWTHandler::verify($token)) { $server->close($request->fd); return; }
    // fd → {trip_id, role} 映射存在 Swoole Table
});

// 司机每秒上报位置
$server->on("message", function ($server, $frame) {
    $data = json_decode($frame->data, true);
    $redis = $GLOBALS['redis'];

    match ($data['type']) {
        'driver:location' => $server->push($frame->fd, '{"ok":true}'),
        // WS Worker 职责仅限：
        //   1) 更新 Redis 实时位置（Swoole Coroutine Redis）
        //   2) LPUSH 到 Redis Streams（让 Octane Worker 去写 PostGIS）
        //   3) 遍历该 trip 频道下的所有乘客 fd，直接 push
        default => null,
    };

    // ---- WS Worker 处理司机位置上报的正确管道 ----
    $redis->setex("driver:position:{$data['driver_id']}", 60, json_encode([
        'lng' => $data['lng'], 'lat' => $data['lat'],
        'ts'  => $data['ts'] ?? time(),
    ]));

    // 推送到 Redis Streams（Octane HTTP Worker 内用 Laravel 消费）
    $redis->lPush("driver:location:stream", json_encode($data));

    // 直接遍历推送该行程所有乘客 WebSocket fd（WS Worker 自己管 fd）
    $tripId = $data['trip_id'];
    $msg = json_encode(['event' => 'driver:location-update', 'data' => $data]);
    $fds = TripChannelTable::getFdsForTrip($tripId); // Swoole\Table
    foreach ($fds as $fd) {
        if ($server->isEstablished($fd)) $server->push($fd, $msg);
    }
});
```

**Octane HTTP Worker 消费管道（写 PostGIS）**：
```php
// 在 Laravel Queue 或 Octane Task Worker 中消费
// app/Jobs/PersistDriverLocationJob.php

class PersistDriverLocationJob implements ShouldQueue
{
    public function handle(): void
    {
        // 从 Redis Streams 批量 pop（用 Laravel Redis facade，这里是 Laravel 环境）
        $batch = Redis::rpop("driver:location:stream", 50);
        if (empty($batch)) return;

        foreach ($batch as $raw) {
            $data = json_decode($raw, true);
            // 双写: PostGIS（历史轨迹） + MySQL（司机最后上报时间）
            DB::table('trip_trajectories')->insert([
                'trip_id'   => $data['trip_id'],
                'driver_id' => $data['driver_id'],
                'location'  => DB::raw("ST_SetSRID(ST_MakePoint({$data['lng']}, {$data['lat']}), 4326)"),
                'timestamp' => now(),
            ]);
        }
    }
}
```

**⚠️ Swoole 进程模型（关键：两进程独立 + Redis 通信）**：

> **为什么是两进程独立？** Octane（Swoole HTTP Server）原生**不提供 WebSocket Server 能力**。
> Octane 内部只启动 HTTP Worker + Task Worker，WebSocket Server 必须由独立的原生 Swoole 进程运行。
> 两进程间通过 **Redis Pub/Sub** + **Redis Streams** 通信解耦。

```
┌────────────────────────────────────────────────────────────────────┐
│  Swoole HTTP Server (Octane, PID A)                                │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌──────────┐ │
│  │ HTTP Worker │  │ HTTP Worker │  │ HTTP Worker │  │ Task Wkr │ │
│  │ (Laravel)   │  │ (Laravel)   │  │ (Laravel)   │  │ (队列)    │ │
│  └─────────────┘  └─────────────┘  └─────────────┘  └──────────┘ │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ Octane Timer Worker (Octane::tick → 每秒异常检测)          │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                   │
│  需要推送时: Redis::publish("ws:trip:{$id}", $message_json)       │
│  需要消费司机位置: Redis::lpop("driver:location:stream")          │
└────────────────────────┬───────────────────────────────────────────┘
                         │ Redis Pub/Sub 跨进程通信
                         ▼
┌────────────────────────────────────────────────────────────────────┐
│  Swoole WS Server (独立原生 Swoole, PID B)                          │
│  ┌─────────────┐  ┌─────────────┐                                │
│  │ WS Worker 1 │  │ WS Worker 2 │   （仅连接管理，无 Laravel）     │
│  └──────┬──────┘  └──────┬──────┘                                │
│         │                │                                        │
│  ┌──────┴────────────────┴──────────────────────────────────────┐ │
│  │  Redis Subscribe Loop（onWorkerStart 内开启协程）            │ │
│  │  subscribe(["ws:trip:456", "ws:trip:789", ...])             │ │
│  │  收到消息 → 遍历该频道下的所有 WebSocket 连接 push            │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                   │
│  司机端上传位置: $server->push 给 HTTP 服务的 HTTP 接口直接接收     │
│                  → 经业务处理 → Redis::lpush("driver:location:stream") │
└────────────────────────────────────────────────────────────────────┘
```

**WS Server 最小骨架代码**（独立进程，文件独立于 Laravel 启动脚本）：
```php
// bin/ws-server.php（不经过 Octane，纯原生 Swoole）
$server = new Swoole\WebSocket\Server("0.0.0.0", 8081);

$server->on("workerStart", function ($server, $workerId) {
    // 每个 WS Worker 内开启 Redis 订阅协程
    $redis = new Swoole\Coroutine\Redis();
    $redis->connect("127.0.0.1", 6379);
    go(function () use ($redis, $server) {
        $redis->subscribe(["ws:broadcast"], function ($redis, $channel, $msg) use ($server) {
            foreach ($server->connections as $fd) {
                $server->push($fd, $msg);
            }
        });
    });
});

$server->on("open", function ($server, $request) { /* 鉴权 + 加入频道 */ });
$server->on("message", function ($server, $frame) { /* 司机位置上报转发 */ });
$server->start();
```

---

### 模块 5：异常检测与自动处理

**职责**：实时检测异常并自动触发处理（改道、通知、重试）。

| 异常类型 | 检测方式 | 阈值 | 自动处理 |
|---------|---------|------|---------|
| 路线偏离 | PostGIS `ST_DWithin(trajectory, planned_route, 200)` | 偏离 > 500m 持续 10s | 重新规划路线 → 推送乘客端 → 调整费用预估 |
| 司机超时 | 司机位置 30s 未更新 | GPS 心跳缺失 | 标记司机离线 → 尝试重新匹配 |
| 交通拥堵 | MCP Server traffic API | 前方 2km 路段拥塞度 > 0.7 | 自动触发改道策略 |
| 接客迟到 | 预计到达时间 > 预约时间 + 15min | 高德实时 ETA | 推送延误通知 + AI 道歉话术 |
| 费用异常 | 实际距离/预估距离 > 1.5x | — | 冻结结算 → 人工介入 |

**实现方式**：Swoole Timer Worker 每秒遍历所有 active 行程，批量检测。

---

### 模块 6：行程总结报告

**职责**：行程完成后，AI 自动生成出行日记 + 费用明细 + 向量化。

```php
// app/Services/AI/TripSummarizer.php

class TripSummarizer
{
    public function summarize(Trip $trip): TripSummary
    {
        $trajectory = PostGIS::getTripTrajectory($trip->id); // 完整轨迹
        $routeCompare = $this->routeCompare($trip);           // 实际 vs 规划路线
        $costBreakdown = $trip->order->toArray();

        // 1. 生成向量存储（用于语义检索"找类似路线"）
        $embedding = AI::embedding()->embed($this->buildTripText($trip));
        DB::table('user_trip_embeddings')->insert([
            'user_id'    => $trip->user_id,
            'trip_id'    => $trip->id,
            'embedding'  => $embedding,
        ]);

        // 2. AI 生成出行日记
        $diary = AI::agent()
            ->withSystemPrompt($this->summarizerPrompt())
            ->withContext([
                'trip'          => $trip->toArray(),
                'trajectory'    => $trajectory,
                'route_compare' => $routeCompare,
                'cost'          => $costBreakdown,
            ])
            ->act('write_trip_diary');

        // 3. 生成费用明细
        $costDetail = $this->buildCostDetail($trip);

        return new TripSummary($diary, $costDetail, $embedding);
    }
}
```

**输出示例**：
```json
{
    "diary": {
        "title": "机场接送 · 国贸清晨出发",
        "story": "今天下午从国贸出发接朋友去首都机场。司机师傅很准时...",
        "highlights": ["一路畅通比预计快了 8 分钟", "国贸上了三环一直往北"],
        "mood": "顺利 ⭐⭐⭐⭐⭐"
    },
    "cost": {
        "base_fare": 18.00,
        "distance_fee": 32.40,
        "time_fee": 12.00,
        "tolls": 10.00,
        "total": 72.40
    },
    "stats": {
        "total_distance_km": 28.5,
        "duration_minutes": 42,
        "route_deviation_m": 0,
        "CO2_kg": 4.28
    }
}
```

---

## 五、数据库设计

### 5.1 MySQL 表结构（事务性数据）

```sql
-- 用户表
CREATE TABLE users (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    phone VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100),
    avatar VARCHAR(255),
    default_city VARCHAR(50) DEFAULT '北京',
    preferences JSON,           -- {"vehicle_pref": "comfort", "price_sensitivity": 0.7}
    created_at DATETIME,
    updated_at DATETIME
);

-- 司机表
CREATE TABLE drivers (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNIQUE REFERENCES users(id),
    license_plate VARCHAR(20),
    vehicle_info JSON,          -- {"model": "丰田凯美瑞", "year": 2022}
    rating DECIMAL(3,2),        -- 平均评分
    total_rides INT DEFAULT 0,
    status ENUM('offline','online_idle','online_busy','on_trip') DEFAULT 'offline',
    certified BOOLEAN DEFAULT FALSE,
    created_at DATETIME,
    updated_at DATETIME
);

-- 行程表（核心主表）
CREATE TABLE trips (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL REFERENCES users(id),
    driver_id BIGINT NULL REFERENCES drivers(id),
    origin_text VARCHAR(200),
    origin_lng DECIMAL(10,7),
    origin_lat DECIMAL(10,7),
    destination_text VARCHAR(200),
    destination_lng DECIMAL(10,7),
    destination_lat DECIMAL(10,7),
    pickup_time DATETIME,
    actual_pickup_time DATETIME,
    dropoff_time DATETIME,
    actual_dropoff_time DATETIME,
    status ENUM('pending','matched','driver_en_route','on_trip','completed','cancelled','exception'),
    constraints JSON,           -- ["顺路可以接"]
    route_plan JSON,            -- 规划的路线方案快照
    cost_snapshot JSON,         -- 费用预估快照
    exception_info JSON NULL,   -- 异常详情
    created_at DATETIME,
    updated_at DATETIME,
    INDEX idx_user_status (user_id, status),
    INDEX idx_driver_status (driver_id, status)
);

-- 订单/支付表
CREATE TABLE orders (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    trip_id BIGINT UNIQUE NOT NULL REFERENCES trips(id),
    driver_id BIGINT NOT NULL REFERENCES drivers(id),
    user_id BIGINT NOT NULL REFERENCES users(id),
    amount DECIMAL(10,2) NOT NULL,
    cost_breakdown JSON,        -- {"base": 18, "distance": 32.4, "tolls": 10, ...}
    payment_method ENUM('alipay','wechat','credit'),
    payment_status ENUM('pending','paid','refunded','disputed'),
    paid_at DATETIME NULL,
    created_at DATETIME
);
```

### 5.2 PostGIS 表结构（空间数据）

```sql
-- 司机实时位置表
CREATE TABLE driver_positions (
    id BIGSERIAL PRIMARY KEY,
    driver_id BIGINT NOT NULL,
    location GEOMETRY(Point, 4326) NOT NULL,
    speed DECIMAL(6,2),        -- km/h
    heading INT,                -- 0-360 度
    timestamp TIMESTAMPTZ NOT NULL,
    CONSTRAINT fk_driver FOREIGN KEY (driver_id) REFERENCES drivers(id)
);

CREATE INDEX idx_driver_positions_gist ON driver_positions USING GIST (location);
CREATE INDEX idx_driver_positions_time ON driver_positions (driver_id, timestamp DESC);

-- 路线规划存储
CREATE TABLE route_segments (
    id BIGSERIAL PRIMARY KEY,
    trip_id BIGINT NOT NULL REFERENCES trips(id),
    strategy VARCHAR(20) NOT NULL,  -- fastest / cheapest / scenic
    geometry GEOMETRY(LineString, 4326) NOT NULL,
    distance_m INT NOT NULL,
    duration_s INT NOT NULL,
    cost DECIMAL(8,2),
    created_at TIMESTAMPTZ
);

CREATE INDEX idx_route_segments_gist ON route_segments USING GIST (geometry);

-- 行程轨迹
CREATE TABLE trip_trajectories (
    id BIGSERIAL PRIMARY KEY,
    trip_id BIGINT NOT NULL REFERENCES trips(id),
    driver_id BIGINT NOT NULL REFERENCES drivers(id),
    location GEOMETRY(Point, 4326) NOT NULL,
    timestamp TIMESTAMPTZ NOT NULL,
    speed DECIMAL(6,2),
    INDEX idx_trip_traj_time ON trip_trajectories (trip_id, timestamp),
    INDEX idx_trip_traj_gist ON trip_trajectories USING GIST (location)
);
```

### 5.3 Redis Key 规范

```
# 司机在线状态（TTL 60s，司机每 5s 续期）
driver:online:{driver_id}         →  "online_idle" | "online_busy" | "matched"
driver:position:{driver_id}       →  {lng, lat, timestamp}  (TTL 60s)

# 地理空间索引（用于快速半径查询）
drivers:geo                        →  GEOADD 存入 司机位置

# 行程实时状态
trip:active:{trip_id}              →  JSON {status, driver, progress}  (TTL 24h)
trip:route:{trip_id}               →  规划路线 polyline  (TTL 2h)

# WebSocket 频道映射
ws:session:{sid}                   →  当前连接归属 (driver_id | user_id)
ws:trip:{trip_id}                  →  该行程订阅的所有 WebSocket 连接

# 缓存
route:cache:{origin_hash}:{dest_hash}:{variant}  →  规划结果  (TTL 10min)
# variant 取值: balanced / fastest / no_highway / scenic
user:preferences:{user_id}         →  用户偏好 JSON  (TTL 1h)
```

### 5.4 pgvector 表结构

```sql
CREATE EXTENSION IF NOT EXISTS vector;

-- 用户出行向量（用于语义检索）
CREATE TABLE user_trip_embeddings (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id),
    trip_id BIGINT NOT NULL REFERENCES trips(id),
    embedding vector(1536),      -- 取决于模型维度，1536 = text-embedding-3-small
    trip_summary TEXT,           -- 原始文本，用于相似度搜索返回
    metadata JSON,               -- {origin, destination, route_type, cost_range}
    created_at TIMESTAMPTZ
);

CREATE INDEX idx_trip_embeddings_hnsw ON user_trip_embeddings
    USING hnsw (embedding vector_cosine_ops)
    WITH (m = 16, ef_construction = 64);
```

---

## 六、API 接口设计

### 6.1 REST API（乘客端）

| 方法 | 路径 | 描述 | 认证 |
|------|------|------|------|
| POST | `/api/v1/trips/parse` | 自然语言意图解析 | 用户 Token |
| POST | `/api/v1/trips/plan` | 基于结构化意图规划路线 | 用户 Token |
| POST | `/api/v1/trips/match` | 选择路线并匹配司机 | 用户 Token |
| GET  | `/api/v1/trips/{id}` | 行程详情（含实时状态） | 用户 Token |
| GET  | `/api/v1/trips/{id}/summary` | 行程总结报告 | 用户 Token |
| GET  | `/api/v1/trips` | 历史行程列表 | 用户 Token |
| POST | `/api/v1/trips/{id}/cancel` | 取消行程 | 用户 Token |

### 6.2 REST API（司机端）

| 方法 | 路径 | 描述 | 认证 |
|------|------|------|------|
| POST | `/api/v1/drivers/login` | 司机登录 | 手机号 |
| POST | `/api/v1/drivers/{id}/status` | 上下线切换 | 司机 Token |
| POST | `/api/v1/drivers/{id}/position` | 上报位置（也走 WebSocket） | 司机 Token |
| POST | `/api/v1/drivers/{id}/accept/{trip_id}` | 接受派单 | 司机 Token |
| POST | `/api/v1/drivers/{id}/arrive/{trip_id}` | 到达接客点 | 司机 Token |
| POST | `/api/v1/drivers/{id}/start/{trip_id}` | 开始行程 | 司机 Token |
| POST | `/api/v1/drivers/{id}/complete/{trip_id}` | 完成行程 | 司机 Token |
| GET  | `/api/v1/drivers/{id}/orders` | 我的订单 | 司机 Token |

### 6.3 WebSocket 事件协议

**连接**：`wss://api.yourdomain.com/ws?token={jwt}`

#### 司机端事件

| 方向 | 事件名 | 消息体 | 说明 |
|------|--------|--------|------|
| C→S | `driver:login` | `{"driver_id": 123}` | 连接后立即上报身份 |
| C→S | `driver:location` | `{"trip_id": 456, "lng": 116.460, "lat": 39.909, "speed": 40, "heading": 180}` | 每秒上报位置 |
| S→C | `trip:assigned` | `{"trip_id": 456, "origin": {...}, "destination": {...}, "fare_preview": 72}` | 收到派单 |
| S→C | `trip:route-update` | `{"trip_id": 456, "new_route": {...}, "reason": "traffic"}` | 改道通知 |

#### 乘客端事件

| 方向 | 事件名 | 消息体 | 说明 |
|------|--------|--------|------|
| C→S | `passenger:subscribe` | `{"trip_id": 456}` | 订阅某个行程的实时频道 |
| S→C | `trip:driver-matched` | `{"driver": {...}, "vehicle": {...}, "eta_min": 8}` | 司机匹配成功 |
| S→C | `driver:location-update` | `{"trip_id": 456, "lng": 116.460, "lat": 39.909, "eta_min": 5}` | 司机实时位置 |
| S→C | `trip:route-deviation` | `{"trip_id": 456, "deviation_m": 520, "new_eta_min": 20}` | 偏离路线告警 |
| S→C | `trip:exception` | `{"trip_id": 456, "type": "driver_timeout", "action": "rematching"}` | 异常事件 |
| S→C | `trip:completed` | `{"trip_id": 456, "order_id": 789, "amount": 72.40}` | 行程结束 |

---

## 七、部署架构

### 7.1 容器编排

```yaml
# docker-compose 服务列表
services:
  # ====== Octane HTTP Server（Laravel 全部业务逻辑运行于此进程）======
  app:
    image: php:8.3-swoole
    command: octane:swoole --host=0.0.0.0 --port=8080
    ports:
      - "8080:8080"     # HTTP REST API
    volumes:
      - ./backend:/var/www/html
    depends_on: [mysql, redis, postgis, rabbitmq]

  # ====== Swoole WS Server（独立原生 Swoole，无 Laravel，纯连接管理）======
  ws:
    image: php:8.3-swoole
    command: php bin/ws-server.php
    ports:
      - "8081:8081"     # WebSocket
    volumes:
      - ./backend:/var/www/html
    depends_on: [redis]

  # 乘客端 Web
  web-passenger:
    build: ./frontend/passenger
    ports: ["3000:3000"]

  # 司机端 Web 管理台
  web-driver:
    build: ./frontend/driver-admin
    ports: ["3001:3001"]

  mysql:8.0
  redis:7-alpine
  postgis:16-3.4
  rabbitmq:3-management
```

### 7.2 生产环境拓扑

```
                    ┌─────────┐
                    │  Nginx  │ (SSL 终结 + 静态资源 + URL 路由)
                    └────┬────┘
                         │
            ┌────────────┼────────────┐
            │            │            │
         /api/*       /ws/*      /*.jsx,/*.css
            │            │            │
            ▼            ▼            ▼
    ┌─────────────┐ ┌────────────┐ ┌──────────────┐
    │ Octane HTTP │ │ Swoole WS  │ │ Next.js SSG  │
    │ Workers x4  │ │ Workers x2 │ │ (passenger)  │
    │ Task Wkr x4 │ │ 独立进程    │ │              │
    │ Timer Wkr   │ │ 无 Laravel │ │              │
    └──────┬──────┘ └──────┬─────┘ └──────────────┘
           │                │
           │  Redis Pub/Sub │   ← 跨进程通信核心
           └────────┬───────┘
                    │
         ┌──────────┼──────────┐
         ▼          ▼          ▼
      MySQL      Redis      PostGIS + pgvector
```

### 7.3 Octane + Swoole 配置要点

```php
// config/octane.php  — 注意：这里只有 HTTP 相关配置，WS 不在这里
'swoole' => [
    'options' => [
        'worker_num'      => 4,      // HTTP Worker 数 = CPU 核数
        'task_worker_num' => 4,      // 异步任务 Worker（队列消费）
        'max_request'     => 1000,   // 防止内存泄漏自动重启 Worker
        'enable_coroutine'=> true,    // 协程调度
        'open_tcp_nodelay'=> true,    // 减少小包延迟
        // ❌ 不要在这里配 WebSocket！Octane 不支持 WebSocket Server
    ],
    // ✅ 定时器（异常检测在这里用 Octane::tick 注册）
    // Octane::tick('detect-exceptions', 1000, [ExceptionDetector::class, 'tick']);
],
```

**关键注意事项（⚠️ 易踩坑）**：
1. **Octane ≠ 完整 Swoole Server**：Octane 封装的是 Swoole HTTP Server，**不提供 WebSocket Server**。WS 必须用原生 `new Swoole\WebSocket\Server()` 独立启动（见 bin/ws-server.php 骨架）
2. **跨进程通信 → Redis Pub/Sub**：HTTP Worker 里 `Redis::publish("ws:trip:{$id}", $msg)`，WS Worker 里 `$redis->subscribe(...)` 收到后 `$server->push()`
3. **WS Server 不要加载 Laravel**：WS Worker 不需要 Eloquent/Container，保持轻量，连接数才能撑住 10w+
4. **定时器异常检测走 Octane::tick**：在 Octane Worker 内 `Octane::tick('check-exceptions', 1000, fn() => ExceptionDetector::tick())`，每秒触发
5. **进程间共享数据用 Redis 而非 Swoole\Table**：因为 HTTP 和 WS 是两个独立 Swoole Server 进程，Swoole\Table 只在单个 Server 的 Master/Worker 间共享，跨进程必须走 Redis

---

## 八、实施路线图

### Phase 1：基础搭建 + ⚠️ MCP 验证（2 周）
- [x] Laravel 11 + Octane 基础项目（backend/，Octane 已装；本地 Windows 无 Swoole 扩展 → artisan serve 开发，生产 docker-compose）
- [x] **⚠️ 高德地图 MCP Server 集成可行性验证（POC 完成，poc/poc.php）**
  - 结论：**不引入 Laravel AI SDK 与 MCP，后端 REST 直调**（详见 2.3 集成路径决策）
  - v5 API 不存在（404）；polyline 仅在 steps[].polyline；strategy 0/4/10 同路线；差异化唯一手段 = waypoints
- [x] 高德地图 API Key 配置与地理编码验证（.env 模板就绪，POC 实测通过）
- [x] MySQL + PostGIS + Redis 本地 Docker Compose（docker-compose.yml 就绪；⚠️ 本机未装 Docker，启动待验证；迁移 2026_10_13_000001~000005）
- [x] Next.js 项目初始化 + 高德地图组件集成（frontend/passenger，AmapMap 组件 + loader 动态导入防 SSR 报错）

### Phase 2：AI + 路线核心（2 周）
- [x] Intent Parser Agent（⚠️ 决策：不引入 Laravel AI SDK，IntentParser OpenAI-compatible HTTP 直调，详见 2.3；实测通过）
- [x] 多策略路线规划（fastest / cheapest / scenic，差异化 = waypoints 绕行点；POST /api/plan + GET /api/routes/compare 实测通过）
- [x] 前端路线方案对比 UI（PlanPanel 方案卡片 + AmapMap polyline 受控渲染，浏览器端到端验证通过）
- [x] 用户历史向量存储（pgvector：迁移 000006 user_trip_embeddings + HNSW 索引、EmbeddingService、PersistTripEmbeddingJob；⚠️ 运行验证依赖 docker PostGIS 容器，与 000005 一起跑）

### Phase 3：实时追踪（2 周）
- [x] Swoole WebSocket Server 搭建（bin/ws-server.php — 原生 Swoole，Pub/Sub 扇出，fdMap 频道订阅表；⚠️ 本地 Windows 无 swoole 扩展 → docker compose up ws 验证）
- [x] 司机端位置上报链路（POST /api/driver/location HTTP 通道 + WS driver:location 消息通道，共用三步管道 setex→lpush→publish）
- [x] 乘客端实时地图追踪（frontend/passenger/app/track/page.tsx + lib/ws.ts 自动重连 + 事件分发，手动模拟司机上报联调通过）
- [x] PostGIS 轨迹双写（PersistDriverLocationJob + php artisan positions:consume 常驻消费，Redis Streams → MySQL drivers.last_position_at + PostGIS trip_trajectories；⚠️ PostGIS 写入降级跳过，不阻塞消费）

### Phase 4：司机匹配 + 异常检测（2 周）
- [ ] 司机端 React Native 原型 App
- [x] PostGIS 就近查询 + AI 评分匹配 — `app/Services/Driver/Matcher.php`（PostGIS ST_DWithin + Redis GEO 两级 fallback + score() 0.4/0.3/0.2/0.1 权重 + findBestDriver）
- [x] Swoole Timer Worker 异常检测 — `bin/ws-server.php` Timer::tick(10000) 扫描 trip:active:*（心跳缺失 30s + 路线偏离 500m），分布式锁防多 worker 重复，publish ws:trip:{id} trip:anomaly 事件
- [x] 路线偏离自动改道 — `app/Services/Geo/AnomalyDetector.php`（Haversine 纯 PHP 偏离算法）+ `TripController@reroute`（RoutePlanner 真实重算 → mock fallback → 更新 Redis trip:route 缓存 → publish trip:route-update）

> 🔧 当前改道流程为**半自动**：Timer tick publish 异常事件 → 前端收到后调 POST /api/trips/{id}/reroute → RoutePlanner 重算。完整自动改道（Timer tick 直接触发 reroute）需 Laravel Queue consumer 或纯 PHP RoutePlanner，留 Step 5+ tech debt。

### Phase 5：闭环 + 报告（Step 5 ✅ 完成）
- [x] 自动结算流程 — `estimateCost()` 精算公式：base=13 + distance×2.5 + duration×0.5 + tolls，total 含 breakdown 数组（base/distance/duration/tolls 各项明细）；`complete()` 事务内自动创建 `orders` 记录
- [x] AI 行程总结 Agent — `app/Services/TripSummarizer.php` 调 LLM（deepseek-flash）生成自然语言总结 → `trips.summary_text` 落库（nullable text 字段）；GET `/api/trips/{id}/summary` 返回 trip + cost + stats + order + summary_text
- [ ] 前端行程报告页 — Next.js 前端，后端 API 已就绪，前端稍后做
- [x] pgvector embedding 异步写入降级 — `PersistTripEmbeddingJob::handle()` try/catch Throwable + Log::warning，EMBEDDING_API_KEY 为空时静默跳过
- [x] 端到端集成测试 — `tests/Feature/TripSummaryTest.php`（5 个测试：费用公式验证 / 零输入边界 / 完整 lifecycle + AI 总结 / 水平权限拦截 / embedding graceful degrade）+ 既有 TripStateMachineTest（6 个）+ AnomalyDetectionTest（8 个）

---

## 九、核心依赖清单

| 依赖 | 包名 | 用途 |
|------|------|------|
| Laravel AI SDK | `laravel/ai` | Agent / 意图解析 / Embedding |
| Octane Swoole | `laravel/octane` + Swoole PHP 扩展 | 高性能 HTTP |
| PostGIS | PostgreSQL 扩展 | 空间计算 |
| pgvector | PostgreSQL 扩展 | 向量存储 |
| Laravel WebSocket 扩展 | `swoole/ide-helper` (配合原生 WS) | 实时连接 |
| 高德地图 JS SDK | `@amap/amap-jsapi-loader` | 前端地图 |
| 高德地理编码/路径 | `amap-maps` (npm) 或直接 REST | 前端辅助 |
| Next.js | `next@15` | SSR 框架 |
| React Native | 0.74+ | 司机端 App |

---

*—— 设计文档结束。下一步建议：按 Phase 1 搭建基础项目骨架，先跑通地理编码 → 路线规划的最小闭环，再接 WebSocket 实时链路。*
