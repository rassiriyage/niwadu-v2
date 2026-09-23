import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  images: {
    qualities: [90],
    formats: ["image/webp"],
    imageSizes: [32, 48, 64, 96, 128, 160, 192, 224, 256, 320, 384, 480],
    minimumCacheTTL: 86400,
  },
  async rewrites() {
    return [{
      source: "/api/v1/:path*",
      destination: `${process.env.API_ORIGIN || "http://127.0.0.1:8000"}/api/v1/:path*`,
    }];
  },
};

export default nextConfig;
