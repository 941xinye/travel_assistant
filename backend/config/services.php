<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // 高德 Web 服务 API（REST 直调，POC 已验证参数契约，见 poc/poc.php）
    'amap' => [
        'key'      => env('AMAP_KEY'),
        'base_uri' => env('AMAP_BASE_URI', 'https://restapi.amap.com/v3/'),
        // 地理编码城市偏好：POC 实测"全国"搜索会让"国贸"命中外地同名点，路线变数千公里
        'city'     => env('AMAP_CITY', '北京'),
    ],

    // LLM（OpenAI-compatible HTTP 直调）
    'llm' => [
        'base_url' => env('LLM_BASE_URL', 'https://api.deepseek.com'),
        'api_key'  => env('LLM_API_KEY'),
        'model'    => env('LLM_MODEL', 'deepseek-chat'),
    ],

    // Embedding（OpenAI-compatible /embeddings；DeepSeek 无 embedding API → 独立供应商）
    'embedding' => [
        'base_url' => env('EMBEDDING_BASE_URL', 'https://api.siliconflow.cn/v1'),
        'api_key'  => env('EMBEDDING_API_KEY'),
        'model'    => env('EMBEDDING_MODEL', 'BAAI/bge-m3'),
        'dim'      => (int) env('EMBEDDING_DIM', 1024),
    ],

    // JWT 鉴权密钥（HS256 对称签名；ws-server.php 原生环境也能读）
    'jwt' => [
        'secret' => env('JWT_SECRET'),
        'ttl'    => (int) env('JWT_TTL', 86400),
    ],

];
