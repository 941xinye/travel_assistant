/** 后端 API 返回类型（对应 PlanController / RouteCompareController） */

export interface Intent {
  origin: { text: string | null; coords: null };
  destination: { text: string | null; coords: null };
  waypoints: { text: string | null; purpose?: string }[];
  pickup_time: string | null;
  passengers: number;
  constraints: string[];
  confidence: number;
  needs_clarification: boolean;
  clarification_question: string | null;
}

export interface RouteOption {
  id: string;
  label: string;
  via: { text: string; location: string }[];
  distance_m: number;
  duration_s: number;
  tolls: string;
  polyline: [number, number][];
  recommended: boolean;
}

export interface PlanResult {
  intent: Intent;
  origin: { text: string; location: string; level: string };
  destination: { text: string; location: string; level: string };
  routes: RouteOption[];
}

/** POST /api/plan 返回 422 时的追问响应 */
export interface ClarifyResult {
  needs_clarification: true;
  question: string;
  intent: Intent;
}
