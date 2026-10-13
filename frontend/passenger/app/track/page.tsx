"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import AmapMap from "@/components/AmapMap";
import { tripSocket, type DriverLocationUpdate } from "@/lib/ws";

type LatLng = [number, number];

const API_BASE = process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8080/api";

/**
 * 乘客端实时追踪（Phase 3 + Step 1 JWT 鉴权）：
 *
 * 两个鉴权通道：
 *   - WebSocket 连接：URL 带 ?token=xxx → ws-server.php open 事件校验
 *   - 模拟司机上报：POST /api/driver/location 必须带 Driver Bearer Token
 *     （Step 1 DriverAuth 中间件保护，不再信任 body driver_id）
 *
 * 开发便捷：track 页提供 token 输入框，复制 register/login 返回的 token 贴入即可。
 * 生产端：token 从 localStorage 读取（AuthController 登录成功后写入）。
 */
export default function TrackPage() {
  const [tripId, setTripId] = useState("1");
  const [status, setStatus] = useState("未连接");
  const [simming, setSimming] = useState(false);
  const [driver, setDriver] = useState<LatLng | undefined>();
  const [logs, setLogs] = useState<string[]>([]);

  // Step 1：JWT token 输入（开发期手动填；生产从 localStorage 读）
  const [wsToken, setWsToken] = useState<string>(() => {
    if (typeof window === "undefined") return "";
    return localStorage.getItem("ws_token") ?? "";
  });
  const [driverToken, setDriverToken] = useState<string>(() => {
    if (typeof window === "undefined") return "";
    return localStorage.getItem("driver_token") ?? "";
  });

  const simTimer = useRef<ReturnType<typeof setInterval> | null>(null);
  const simStep = useRef(0);

  // 挂载 WS 事件监听（重连由 tripSocket 自动处理）
  useEffect(() => {
    const off = tripSocket.onEvent((msg) => {
      if (msg.event === "driver:location-update") {
        const d = msg.data as unknown as DriverLocationUpdate;
        setDriver([d.lng, d.lat]);
        setLogs((prev) =>
          [
            `[${new Date(d.ts * 1000).toLocaleTimeString()}] 司机 ${d.driver_id} → ${d.lng.toFixed(5)}, ${d.lat.toFixed(5)}${d.speed != null ? ` · ${d.speed}km/h` : ""}`,
            ...prev,
          ].slice(0, 20),
        );
      } else if (msg.event === "subscribed") {
        setStatus(`已订阅行程 #${msg.data.trip_id}，等待司机位置…`);
      }
    });
    return off;
  }, []);

  const handleSubscribe = useCallback(() => {
    const id = Number(tripId);
    if (!Number.isInteger(id) || id <= 0) {
      setStatus("trip_id 无效");
      return;
    }
    // Step 1：注入 JWT 到 TripSocket（生产从 localStorage 读，开发从输入框拿）
    tripSocket.setToken(wsToken || null);
    setStatus(`连接中 ${process.env.NEXT_PUBLIC_WS_URL ?? "ws://localhost:8081"} …${wsToken ? " (已带JWT)" : " (无token，依赖ws-server开发兜底)"}`);
    tripSocket.subscribeTrip(id);
  }, [tripId, wsToken]);

  // Step 1：模拟上报必须带 Driver Bearer Token
  const toggleSim = useCallback(() => {
    if (simTimer.current) {
      clearInterval(simTimer.current);
      simTimer.current = null;
      setSimming(false);
      setLogs((p) => ["—— 模拟已停止 ——", ...p]);
      return;
    }
    if (! driverToken) {
      setLogs((p) => ["⚠️ 需要先登录司机账号贴 token（或 POST /api/drivers/register 拿到）", ...p]);
      return;
    }
    simStep.current = 0;
    setSimming(true);
    setLogs((p) => ["—— 模拟已开始 ——", ...p]);
    simTimer.current = setInterval(() => {
      const s = simStep.current++;
      fetch(`${API_BASE}/driver/location`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${driverToken}`,
        },
        body: JSON.stringify({
          trip_id: Number(tripId) || 1,
          lng: 116.397 + s * 0.0004,
          lat: 39.909 + s * 0.0003,
          speed: 30 + (s % 5) * 3,
          heading: 45,
        }),
      }).catch((e) => {
        setLogs((p) => [`模拟上报失败: ${e.message}`, ...p].slice(0, 20));
      });
    }, 1000);
  }, [tripId, driverToken]);

  // token 变化时持久化到 localStorage
  useEffect(() => { localStorage.setItem("ws_token", wsToken); }, [wsToken]);
  useEffect(() => { localStorage.setItem("driver_token", driverToken); }, [driverToken]);

  // 卸载清理
  useEffect(
    () => () => {
      if (simTimer.current) clearInterval(simTimer.current);
      tripSocket.close();
    },
    [],
  );

  return (
    <div className="relative h-dvh w-full">
      <AmapMap className="absolute inset-0 h-full w-full" driver={driver} />

      <div className="absolute left-4 top-4 z-10 w-[380px] space-y-3 rounded-2xl bg-white/95 p-4 shadow-lg backdrop-blur">
        <div className="flex items-center justify-between">
          <h1 className="text-base font-semibold text-gray-900">实时追踪 Demo</h1>
          <Link href="/" className="text-xs text-blue-600 hover:underline">
            ← 返回规划
          </Link>
        </div>

        {/* Step 1：JWT token 输入区 */}
        <div className="space-y-2 rounded-lg bg-gray-50 p-2">
          <div className="text-[11px] font-medium text-gray-500">JWT（开发期手动贴；生产从登录态自动注入）</div>
          <input
            value={wsToken}
            onChange={(e) => setWsToken(e.target.value)}
            placeholder="乘客/通用 token（用于 WS 连接）"
            className="w-full rounded-md border border-gray-200 px-2 py-1 text-[11px] outline-none focus:border-blue-400"
          />
          <input
            value={driverToken}
            onChange={(e) => setDriverToken(e.target.value)}
            placeholder="司机 token（用于模拟上报）"
            className="w-full rounded-md border border-gray-200 px-2 py-1 text-[11px] outline-none focus:border-blue-400"
          />
        </div>

        <div className="flex gap-2">
          <input
            value={tripId}
            onChange={(e) => setTripId(e.target.value)}
            className="w-24 rounded-lg border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-blue-400"
            placeholder="trip_id"
          />
          <button
            onClick={handleSubscribe}
            className="flex-1 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
          >
            订阅行程
          </button>
          <button
            onClick={toggleSim}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium ${simming ? "bg-red-50 text-red-600 hover:bg-red-100" : "bg-gray-100 text-gray-700 hover:bg-gray-200"}`}
          >
            {simming ? "停止模拟" : "模拟司机"}
          </button>
        </div>

        <p className="text-xs text-gray-500">{status}</p>

        <div className="max-h-48 space-y-1 overflow-y-auto rounded-lg bg-gray-50 p-2 font-mono text-[11px] leading-relaxed text-gray-600">
          {logs.length === 0 ? <p className="text-gray-400">暂无事件（填 token → 订阅 → 开模拟）</p> : logs.map((l, i) => <p key={i}>{l}</p>)}
        </div>
      </div>
    </div>
  );
}
