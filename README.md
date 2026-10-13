# 🚗 全栈智能出行助手

> AI + 地图 + 实时追踪，一句话打车的全栈出行平台

用户用**自然语言**描述出行需求（"明天下午3点从国贸到首都机场，要接一个朋友"），AI 自动完成意图解析 → 多策略路线规划 → 司机智能匹配 → 全程实时追踪 → 到达后费用精算 + AI 生成行程总结的完整闭环。

---

## 🛠 技术栈

| 层级 | 技术 | 说明 |
|------|------|------|
| 后端框架 | Laravel 11 + Octane (Swoole) | 协程常驻内存，3ms 冷启动 |
| 实时服务 | 原生 Swoole WebSocket | `bin/ws-server.php` 独立进程，Pub/Sub 扇出 |
| LLM | deepseek-flash (OpenAI-compatible) | IntentParser + TripSummarizer |
| 地图 | 高德地图 API + JS SDK v2.0 | 地理编码 / 路径规划 / 前端地图渲染 |
| 缓存 & GEO | Redis 7.x | 司机位置、Geo 就近索引、分布式锁、跨进程 Pub/Sub |
| 关系库 | MySQL 8.0 | 用户 / 订单 / 行程事务数据 |
| 空间库 | PostGIS + pgvector | 空间查询 + 历史出行向量（testing 环境自动降级） |
| 前端（乘客） | Next.js 15 (App Router) + 高德地图组件 | SSR + 实时追踪页 |
| 前端（司机） | React Native | 📋 待实现 |
| 容器化 | Docker + Docker Compose | PostGIS / Redis / MySQL / WS Server |

---

## ✅ 已实现功能（Phase 1 ~ 5 全部完成）

### Phase 1 — 基础搭建
- [x] Laravel 11 + Octane 项目骨架（`backend/`）
- [x] Next.js 15 乘客端骨架 + 高德地图组件（`frontend/passenger/`）
- [x] 高德地图 MCP POC 验证（`poc/poc.php`，结论：REST 直调不引入 Laravel AI SDK）
- [x] Docker Compose（PostGIS + Redis + MySQL 就绪，本机装 Docker 后一键启动）

### Phase 2 — AI + 路线核心
- [x] **AI 意图解析 Agent** — `app/Services/AI/IntentParser.php` 自然语言 → JSON 结构化参数
- [x] **多策略路线规划** — `RoutePlanner.php` 支持 fastest / cheapest / scenic
- [x] **路线对比 API** — `POST /api/plan` + `GET /api/routes/compare`
- [x] **前端路线方案对比 UI** — PlanPanel 卡片 + AmapMap polyline 受控渲染
- [x] **pgvector 用户历史向量存储** — 迁移 000006 + `EmbeddingService` + `PersistTripEmbeddingJob`

### Phase 3 — 实时追踪
- [x] **Swoole WebSocket Server** — `bin/ws-server.php`，原生 Swoole，Redis Pub/Sub 跨进程扇出
- [x] **司机位置上报** — HTTP `POST /api/driver/location` + WS `driver:location` 双通道，三步管道 `setex → lpush → publish`
- [x] **乘客实时追踪页** — `frontend/passenger/app/track/page.tsx` + `lib/ws.ts` 自动重连 + 事件分发
- [x] **PostGIS 轨迹双写** — `PersistDriverLocationJob` + `php artisan positions:consume`（PostGIS 写入失败自动降级）

### Phase 4 — 司机匹配 + 异常检测
- [x] **两级司机匹配** — `Matcher.php`：PostGIS `ST_DWithin` + Redis GEO fallback + `score()` 0.4/0.3/0.2/0.1 权重评分
- [x] **Swoole Timer 异常检测** — 每 10s 扫描 `trip:active:*`，检测心跳缺失 30s + 路线偏离 500m，分布式锁防多 worker 重复，publish `trip:anomaly`
- [x] **路线改道 API** — `POST /api/trips/{id}/reroute`，RoutePlanner 真实重算 → mock fallback → 更新 Redis `trip:route` → publish `trip:route-update`

### Phase 5 — 闭环 + 报告
- [x] **费用精算公式** — `estimateCost()` 升级：`base=13 + distance×2.5 + duration×0.5 + tolls`，total 含 breakdown 数组（base/distance/duration/tolls 各项明细）
- [x] **自动结算** — `TripOrchestrator::complete()` 事务内自动创建 `orders` 记录
- [x] **AI 行程总结 Agent** — `TripSummarizer.php` 调 LLM 生成自然语言总结 → `trips.summary_text` 落库
- [x] **行程报告 API** — `GET /api/trips/{id}/summary` 返回 trip + cost(含 breakdown) + stats + order + summary_text
- [x] **pgvector 异步写入降级** — `PersistTripEmbeddingJob::handle()` try/catch + Log::warning，无 API key 时静默跳过
- [x] **端到端集成测试** — 20 套件全绿，88 assertions（详见下方"测试覆盖"）

---

## 📋 待实现功能

| 功能 | 说明 | 优先级 |
|------|------|--------|
| 司机端 React Native App | 司机接单、导航、位置持续上报 | 高 |
| 前端行程报告页 | Next.js 页面消费 `GET /api/trips/{id}/summary` | 高（后端 API 已就绪） |
| Timer tick 自动改道 | 当前改道是半自动（Timer 发异常事件 → 前端调 reroute），需 Queue consumer 或纯 PHP RoutePlanner 实现全自动 | 中 |
| 支付集成 | 微信支付 / 支付宝对接 Order `payment_status` | 中 |
| 用户历史向量语义检索 | "我常去哪儿""我喜欢什么路线"查询消费 pgvector | 中 |
| 多语言 & 国际化 | 英文界面 / 外籍司机 | 低 |

---

## 🔌 API 路由一览

### 公共路由（无需鉴权）

| Method | Path | 说明 |
|--------|------|------|
| POST | `/api/auth/register` | 乘客注册 |
| POST | `/api/auth/login` | 乘客登录（返回 JWT） |
| POST | `/api/drivers/register` | 司机注册 |
| POST | `/api/drivers/login` | 司机登录（返回 JWT） |
| POST | `/api/plan` | AI 意图解析 + 多策略路线规划 |
| GET | `/api/routes/compare` | 路线方案对比 |

### 乘客端（JwtAuth 保护）

| Method | Path | 说明 |
|--------|------|------|
| POST | `/api/trips` | 创建行程（从 RoutePlanner 结果落库） |
| POST | `/api/trips/{id}/match` | 触发司机匹配 |
| GET | `/api/trips/{id}` | 行程详情 |
| GET | `/api/trips` | 我的历史行程 |
| POST | `/api/trips/{id}/cancel` | 取消行程 |
| POST | `/api/trips/{id}/reroute` | 路线改道（异常检测后触发） |
| GET | `/api/trips/{id}/summary` | 行程报告（费用 + AI 总结） |

### 司机端（DriverAuth 保护）

| Method | Path | 说明 |
|--------|------|------|
| POST | `/api/drivers/{id}/status` | 司机上下线 |
| POST | `/api/driver/location` | 位置上报 |
| POST | `/api/drivers/{id}/accept/{trip_id}` | 接单 |
| POST | `/api/drivers/{id}/arrive/{trip_id}` | 到达上车点 |
| POST | `/api/drivers/{id}/start/{trip_id}` | 开始行程 |
| POST | `/api/drivers/{id}/complete/{trip_id}` | 完成行程（自动结算 + AI 总结） |
| GET | `/api/drivers/{id}/orders` | 我的订单 |

---

## 🧪 测试覆盖

```
php artisan test
```

| 测试套件 | 测试数 | 覆盖内容 |
|----------|--------|---------|
| TripStateMachineTest | 6 | 全生命周期 create→match→accept→arrive→start→complete / 反向转换拦截 / 水平权限 / Matcher Geo 查找 |
| AnomalyDetectionTest | 8 | Haversine 偏离检测 / Timer tick 扫描 / 分布式锁 / reroute 端到端 / Redis trip:active 键管理 |
| TripSummaryTest | 5 | 费用公式验证 / 零输入边界 / 完整 lifecycle+AI 总结 / 水平权限拦截 / embedding graceful degrade |
| ExampleTest | 2 | 基础冒烟 |

**20 套件全绿 · 88 assertions · 0 FAIL**（testing 环境 sqlite :memory: + RefreshDatabase + Redis flushDB）

---

## 🚀 快速开始

### 环境要求

- PHP 8.2+（本地开发用 artisan serve；生产用 Swoole Octane）
- Node.js 18+
- Docker（PostGIS / Redis / MySQL；本机无 Docker 可用 SQLite :memory: 跑测试）
- Redis（本地或 Docker）
- LLM API Key（deepseek-flash 或兼容 OpenAI）
- 高德地图 API Key

### 后端启动

```bash
cd backend
composer install
cp .env.example .env   # 填入 LLM_API_KEY / AMAP_KEY / 数据库配置
php artisan key:generate
php artisan migrate --force
php artisan serve --host=0.0.0.0 --port=8000

# 跑测试（sqlite :memory: 无需外部依赖）
php artisan test
```

### Swoole WebSocket Server（生产）

```bash
php bin/ws-server.php
```

### 前端启动

```bash
cd frontend/passenger
npm install
npm run dev
# → http://localhost:3000
```

### Docker Compose（生产环境）

```bash
docker compose up -d
```

---

## 📂 目录结构

```
全栈智能出行助手/
├── backend/                   # Laravel 11 + Octane 后端
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/   # Auth / Trip / Driver / Plan / RouteCompare
│   │   │   └── Middleware/    # JwtAuth / DriverAuth
│   │   ├── Models/           # User / Driver / Trip / Order
│   │   ├── Services/
│   │   │   ├── AI/            # IntentParser / TripSummarizer
│   │   │   ├── Driver/       # Matcher（PostGIS + Redis GEO 两级匹配）
│   │   │   ├── Geo/          # RoutePlanner / AmapService / AnomalyDetector
│   │   │   └── TripOrchestrator.php   # 行程状态机 + 费用精算
│   │   ├── Jobs/             # PersistTripEmbeddingJob / PersistDriverLocationJob
│   │   └── Support/          # Jwt
│   ├── bin/
│   │   └── ws-server.php     # Swoole WebSocket Server（原生，独立进程）
│   ├── routes/api.php        # 全部 API 路由
│   ├── database/migrations/  # 7 个迁移（MySQL 主表 + PostGIS/pgvector）
│   └── tests/Feature/        # TripStateMachineTest / AnomalyDetectionTest / TripSummaryTest
├── frontend/
│   └── passenger/            # Next.js 15 乘客端
│       ├── app/
│       │   ├── page.tsx      # 首页（自然语言输入 → 路线对比）
│       │   └── track/page.tsx  # 实时追踪页
│       ├── components/
│       │   └── AmapMap.tsx   # 高德地图组件
│       └── lib/
│           ├── api.ts        # API client
│           └── ws.ts         # WebSocket 客户端（自动重连 + 事件分发）
├── poc/                      # 高德地图 API POC（验证 + 结论：REST 直调）
├── docker/
│   └── postgis/Dockerfile
├── docker-compose.yml        # PostGIS + Redis + MySQL + WS Server
├── ARCHITECTURE.md           # 完整架构设计文档
└── README.md                 # 本文件
```

---

## 📐 架构设计

完整架构设计详见 [ARCHITECTURE.md](./ARCHITECTURE.md)，涵盖：

- 技术选型矩阵与决策理由
- 数据库 Schema（MySQL 主表 + PostGIS 空间表 + pgvector 向量表）
- Redis 键设计与 Pub/Sub 事件总线
- 行程状态机流转图
- WebSocket 跨进程通信模式
- Swoole Timer Worker 异常检测机制

---

## 🏗 费用精算公式（Step 5）

```
起步价     = 13.0 元
里程费     = distance_m / 1000 × 2.5  元/km
时长费     = duration_s / 60 × 0.5   元/min
过路费     = tolls
────────────────────────────────────
总计       = 起步价 + 里程费 + 时长费 + 过路费
```

响应 `cost_snapshot` 含 `breakdown` 数组，前端可逐项展示费用明细。

---

## 📜 License

MIT
