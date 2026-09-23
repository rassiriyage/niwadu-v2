import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  images: { qualities: [90] },
  async rewrites() {
    return [{
      source: "/api/v1/:path*",
      destination: `${process.env.API_ORIGIN || "http://127.0.0.1:8000"}/api/v1/:path*`,
    }];
  },
};

export default nextConfig;
