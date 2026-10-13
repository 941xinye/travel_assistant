"use client";

import { useEffect, useRef } from "react";

type LatLng = [number, number];

/** 高德 JS API v2.0 局部类型声明（仅本项目用到的能力） */
interface AMapLine {
  setMap(map: unknown): void;
}
interface AMapMarker {
  setMap(map: unknown): void;
  setPosition(p: LatLng): void;
}
interface AMapMapInstance {
  add(overlay: unknown): void;
  setFitView(overlays?: unknown): void;
  destroy(): void;
}
interface AMapNamespace {
  Map: new (el: HTMLElement, opts: object) => AMapMapInstance;
  Polyline: new (opts: {
    path: LatLng[];
    strokeColor: string;
    strokeWeight: number;
    strokeOpacity: number;
    lineJoin: string;
  }) => AMapLine;
  Marker: new (opts: {
    position: LatLng;
    content?: string;
  }) => AMapMarker;
}

interface AmapMapProps {
  className?: string;
  /** 当前选中路线的折线（"lng,lat" 数组），变化时重绘并拟合视野 */
  polyline?: LatLng[];
  /** 司机实时位置（追踪页，Phase 3），变化时平滑移动标记 */
  driver?: LatLng;
}

/**
 * 高德地图 JS API v2.0 封装（ARCHITECTURE.md Phase 1/2）。
 * - loader 在 useEffect 内动态 import，避免 SSR/prerender 访问 window
 * - Key 从 NEXT_PUBLIC_AMAP_JS_KEY 读取；未配置时渲染占位提示
 * - polyline prop 受控：变化时重绘 Polyline 并 setFitView
 */
export default function AmapMap({ className = "", polyline, driver }: AmapMapProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<AMapMapInstance | null>(null);
  const amapRef = useRef<AMapNamespace | null>(null);
  const lineRef = useRef<AMapLine | null>(null);
  const markerRef = useRef<AMapMarker | null>(null);
  const readyRef = useRef<Promise<void> | null>(null);

  // 初始化（仅一次）
  useEffect(() => {
    const key = process.env.NEXT_PUBLIC_AMAP_JS_KEY;
    const container = containerRef.current;
    if (!key || !container) return;

    let cancelled = false;

    readyRef.current = import("@amap/amap-jsapi-loader")
      .then(({ default: AMapLoader }) => AMapLoader.load({ key, version: "2.0" }))
      .then((AMap: AMapNamespace) => {
        if (cancelled) return;
        amapRef.current = AMap;
        mapRef.current = new AMap.Map(container, {
          zoom: 11,
          center: [116.397, 39.909], // 北京
          viewMode: "2D",
        });
      })
      .catch((e: unknown) => {
        console.error("AMap load failed:", e);
      });

    return () => {
      cancelled = true;
      mapRef.current?.destroy();
      mapRef.current = null;
    };
  }, []);

  // polyline 变化 → 重绘 + 拟合视野（等 map ready）
  useEffect(() => {
    if (!polyline?.length) return;
    readyRef.current?.then(() => {
      const map = mapRef.current;
      const amap = amapRef.current;
      if (!map || !amap) return;

      lineRef.current?.setMap(null);
      const line = new amap.Polyline({
        path: polyline,
        strokeColor: "#1677ff",
        strokeWeight: 6,
        strokeOpacity: 0.9,
        lineJoin: "round",
      });
      map.add(line);
      lineRef.current = line;
      map.setFitView([line]);
    });
  }, [polyline]);

  // driver 变化 → 创建/移动司机标记（等 map ready）
  useEffect(() => {
    if (!driver) return;
    readyRef.current?.then(() => {
      const map = mapRef.current;
      const amap = amapRef.current;
      if (!map || !amap) return;

      if (!markerRef.current) {
        markerRef.current = new amap.Marker({
          position: driver,
          content:
            '<div style="width:14px;height:14px;border-radius:50%;background:#1677ff;border:2px solid #fff;box-shadow:0 0 4px rgba(0,0,0,.3)"></div>',
        });
        map.add(markerRef.current);
      } else {
        markerRef.current.setPosition(driver);
      }
    });
  }, [driver]);

  if (!process.env.NEXT_PUBLIC_AMAP_JS_KEY) {
    return (
      <div className={`flex items-center justify-center bg-gray-100 text-sm text-gray-500 ${className}`}>
        地图未启用：请配置 NEXT_PUBLIC_AMAP_JS_KEY（高德 JS API Key）
      </div>
    );
  }

  return <div ref={containerRef} className={className} />;
}
