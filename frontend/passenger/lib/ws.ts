/** S→C 事件负载（ARCHITECTURE.md 6.3） */
export interface DriverLocationUpdate {
  trip_id: number;
  driver_id: number;
  lng: number;
  lat: number;
  speed: number | null;
  heading: number | null;
  ts: number;
}

export interface WsMessage {
  event: string;
  data: Record<string, unknown>;
}

const WS_BASE = process.env.NEXT_PUBLIC_WS_URL ?? "ws://localhost:8081";

/**
 * 轻量 WS 客户端（Phase 3 乘客端追踪 + Step 1 JWT 鉴权）：
 * - 单例：追踪页与后续行程页共享一条连接
 * - 自动重连（指数退避，8s 封顶），重连后自动恢复 passenger:subscribe
 * - JWT：token 由 setToken() 设置，连接 URL 以 ?token={jwt} 传递（ws-server.php 校验）
 *   - 开发期无 token 时，ws-server.php 若 JWT_SECRET 未配置会放行（role=anonymous）
 */
class TripSocket {
  private ws: WebSocket | null = null;
  private tripId: number | null = null;
  private token: string | null = null;
  private listeners = new Set<(msg: WsMessage) => void>();
  private retry = 0;
  private closedByUser = false;

  /** 设置 JWT（乘客登录后调用；也接受司机 token 用于模拟等场景） */
  setToken(token: string | null): void {
    this.token = token;
  }

  connect(): void {
    if (this.ws && this.ws.readyState <= WebSocket.OPEN) return;
    this.closedByUser = false;

    // 拼接 ?token=xxx query 参数（ws-server.php open 事件解析）
    const url = this.token ? `${WS_BASE}?token=${encodeURIComponent(this.token)}` : WS_BASE;
    const ws = new WebSocket(url);
    this.ws = ws;

    ws.onopen = () => {
      this.retry = 0;
      if (this.tripId !== null) {
        this.send({ type: "passenger:subscribe", trip_id: this.tripId });
      }
    };

    ws.onmessage = (ev: MessageEvent<string>) => {
      try {
        const msg = JSON.parse(ev.data) as WsMessage;
        this.listeners.forEach((fn) => fn(msg));
      } catch {
        /* 忽略非 JSON 帧（ack 等） */
      }
    };

    ws.onclose = () => {
      if (this.closedByUser) return;
      const delay = Math.min(1000 * 2 ** this.retry++, 8000);
      setTimeout(() => this.connect(), delay);
    };

    ws.onerror = () => ws.close();
  }

  /** 订阅行程实时频道（重连后由 onopen 自动恢复） */
  subscribeTrip(tripId: number): void {
    this.tripId = tripId;
    this.connect();
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.send({ type: "passenger:subscribe", trip_id: tripId });
    }
  }

  close(): void {
    this.closedByUser = true;
    this.tripId = null;
    this.ws?.close();
    this.ws = null;
  }

  onEvent(fn: (msg: WsMessage) => void): () => void {
    this.listeners.add(fn);
    return () => {
      this.listeners.delete(fn);
    };
  }

  private send(payload: object): void {
    this.ws?.send(JSON.stringify(payload));
  }
}

export const tripSocket = new TripSocket();
