import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Docker 部署需要 standalone 输出（见 Dockerfile）
  output: "standalone",
};

export default nextConfig;
