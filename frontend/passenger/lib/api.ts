import type { ClarifyResult, PlanResult } from "@/types/plan";

const API_BASE = process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8080/api";

/**
 * POST /api/plan — 自然语言 → 意图解析 + 多方案路线对比。
 * 422 表示需要追问（needs_clarification），其余错误抛异常。
 */
export async function requestPlan(input: string): Promise<PlanResult | ClarifyResult> {
  const res = await fetch(`${API_BASE}/plan`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ input }),
  });

  if (!res.ok) {
    const body = await res.json().catch(() => null);
    if (res.status === 422 && body?.needs_clarification) {
      return body as ClarifyResult;
    }
    throw new Error(body?.message ?? `规划请求失败 (${res.status})`);
  }

  return (await res.json()) as PlanResult;
}
