<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\DriverLocationController;
use App\Http\Controllers\DriverTripController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\RouteCompareController;
use App\Http\Controllers\TripController;
use Illuminate\Support\Facades\Route;

// ===== 公共路由（无需鉴权）=====

// 乘客端注册/登录
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// 司机端注册/登录
Route::post('/drivers/register', [DriverController::class, 'register']);
Route::post('/drivers/login', [DriverController::class, 'login']);

// AI 意图解析 + 多策略路线对比（Phase 2 核心；当前 open 供 POC 调试，Phase 4 后加 JwtAuth）
Route::post('/plan', [PlanController::class, 'store']);
Route::get('/routes/compare', [RouteCompareController::class, 'index']);

// ===== 乘客端路由（JwtAuth 保护）=====
Route::middleware('jwt.auth')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Step 2：行程状态机
    Route::post('/trips', [TripController::class, 'create']);                 // 创建（从 RoutePlanner 结果落库）
    Route::post('/trips/{id}/match', [TripController::class, 'match']);      // 触发司机匹配
    Route::get('/trips/{id}', [TripController::class, 'show']);              // 行程详情
    Route::get('/trips', [TripController::class, 'index']);                 // 我的历史行程
    Route::post('/trips/{id}/cancel', [TripController::class, 'cancel']);    // 取消行程
    Route::post('/trips/{id}/reroute', [TripController::class, 'reroute']); // Step 4：路线改道
    Route::get('/trips/{id}/summary', [TripController::class, 'summary']);  // Step 5：行程报告
});

// ===== 司机端路由（DriverAuth 保护）=====
Route::middleware('driver.auth')->group(function () {
    // 司机上下线
    Route::post('/drivers/{id}/status', [DriverController::class, 'updateStatus']);

    // 司机端位置上报 HTTP 通道（与 WS driver:location 共用三步管道）
    Route::post('/driver/location', [DriverLocationController::class, 'store']);

    // Step 2：司机端行程操作（状态流转由 TripOrchestrator 统一管理）
    Route::post('/drivers/{id}/accept/{trip_id}', [DriverTripController::class, 'accept']);
    Route::post('/drivers/{id}/arrive/{trip_id}', [DriverTripController::class, 'arrive']);
    Route::post('/drivers/{id}/start/{trip_id}', [DriverTripController::class, 'start']);
    Route::post('/drivers/{id}/complete/{trip_id}', [DriverTripController::class, 'complete']);
    Route::get('/drivers/{id}/orders', [DriverTripController::class, 'orders']);
});
