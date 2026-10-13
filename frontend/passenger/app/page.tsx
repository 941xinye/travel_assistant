"use client";

import Link from "next/link";
import { useState } from "react";
import AmapMap from "@/components/AmapMap";
import PlanPanel from "@/components/PlanPanel";
import { requestPlan } from "@/lib/api";
import type { Intent, RouteOption } from "@/types/plan";

type Status = "idle" | "loading" | "clarify" | "plan";

/** 乘客端首页：一句话出行 → AI 意图解析 → 多方案对比 → 地图渲染 */
export default function Home() {
  const [status, setStatus] = useState<Status>("idle");
  const [routes, setRoutes] = useState<RouteOption[]>([]);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [question, setQuestion] = useState<string | null>(null);
  const [intent, setIntent] = useState<Intent | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(input: string) {
    setStatus("loading");
    setError(null);
    setQuestion(null);
    try {
      const result = await requestPlan(input);
      if ("needs_clarification" in result) {
        setQuestion(result.question);
        setIntent(result.intent);
        setStatus("clarify");
        return;
      }
      setIntent(result.intent);
      setRoutes(result.routes);
      setSelectedId(result.routes.find((r) => r.recommended)?.id ?? result.routes[0]?.id ?? null);
      setStatus("plan");
    } catch (e) {
      setError(e instanceof Error ? e.message : "未知错误");
      setStatus("idle");
    }
  }

  const selected = routes.find((r) => r.id === selectedId);

  return (
    <div className="relative h-dvh w-full">
      {/* 全屏地图：跟随选中方案渲染 polyline */}
      <AmapMap className="absolute inset-0 h-full w-full" polyline={selected?.polyline} />

      <Link
        href="/track"
        className="absolute right-4 top-4 z-10 rounded-full bg-white/95 px-4 py-2 text-sm font-medium text-gray-700 shadow-lg backdrop-blur hover:bg-white"
      >
        实时追踪 Demo →
      </Link>

      <PlanPanel
        status={status}
        routes={routes}
        selectedId={selectedId}
        question={question}
        intent={intent}
        error={error}
        onSelect={setSelectedId}
        onSubmit={handleSubmit}
      />
    </div>
  );
}
