"use client";

import { useState } from "react";
import type { Intent, RouteOption } from "@/types/plan";

type Status = "idle" | "loading" | "clarify" | "plan";

interface PlanPanelProps {
  status: Status;
  routes: RouteOption[];
  selectedId: string | null;
  question: string | null;
  intent: Intent | null;
  error: string | null;
  onSelect: (id: string) => void;
  onSubmit: (input: string) => void;
}

function formatDistance(meters: number): string {
  return meters >= 1000 ? `${(meters / 1000).toFixed(1)} km` : `${meters} m`;
}

function formatDuration(seconds: number): string {
  const min = Math.round(seconds / 60);
  return min >= 60 ? `${Math.floor(min / 60)}h${min % 60}min` : `${min} min`;
}

/** 底部浮层：自然语言输入 → 澄清追问 → 路线方案卡片列表 */
export default function PlanPanel({
  status,
  routes,
  selectedId,
  question,
  intent,
  error,
  onSelect,
  onSubmit,
}: PlanPanelProps) {
  const [input, setInput] = useState("");

  const canSubmit = input.trim().length >= 2 && status !== "loading";

  return (
    <div className="absolute inset-x-0 bottom-0 p-4 sm:p-6">
      <div className="mx-auto max-w-xl rounded-2xl bg-white/95 p-4 shadow-lg backdrop-blur sm:p-5">
        <h1 className="mb-1 text-lg font-semibold text-gray-900">全栈智能出行助手</h1>
        {status === "plan" && intent?.origin.text && intent.destination.text && (
          <p className="mb-2 text-sm text-gray-600">
            {intent.origin.text} → {intent.destination.text}
            {intent.pickup_time ? ` · ${intent.pickup_time.slice(0, 16).replace("T", " ")}` : ""}
          </p>
        )}

        {/* 输入表单 */}
        <form
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            if (canSubmit) onSubmit(input.trim());
          }}
        >
          <input
            value={input}
            onChange={(e) => setInput(e.target.value)}
            placeholder="说出你的出行需求…"
            className="h-11 flex-1 rounded-xl border border-gray-200 px-3 text-sm outline-none focus:border-blue-500"
          />
          <button
            type="submit"
            disabled={!canSubmit}
            className="h-11 rounded-xl bg-blue-600 px-5 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {status === "loading" ? "解析中…" : "智能规划"}
          </button>
        </form>

        {/* 错误提示 */}
        {error && (
          <div className="mt-3 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-600">{error}</div>
        )}

        {/* 追问（needs_clarification） */}
        {status === "clarify" && question && (
          <div className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-700">
            {question}
          </div>
        )}

        {/* 方案卡片 */}
        {status === "plan" && (
          <ul className="mt-3 max-h-64 space-y-2 overflow-y-auto">
            {routes.map((route) => {
              const selected = route.id === selectedId;
              return (
                <li key={route.id}>
                  <button
                    type="button"
                    onClick={() => onSelect(route.id)}
                    className={`w-full rounded-xl border px-4 py-3 text-left transition-colors ${
                      selected
                        ? "border-blue-500 bg-blue-50"
                        : "border-gray-200 bg-white hover:border-blue-300"
                    }`}
                  >
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-medium text-gray-900">{route.label}</span>
                      {route.recommended && (
                        <span className="rounded-full bg-blue-600 px-2 py-0.5 text-xs text-white">
                          推荐
                        </span>
                      )}
                    </div>
                    <div className="mt-1 text-xs text-gray-500">
                      {formatDuration(route.duration_s)} · {formatDistance(route.distance_m)}
                      {Number(route.tolls) > 0 ? ` · 过路费 ¥${route.tolls}` : " · 免过路费"}
                    </div>
                  </button>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}
