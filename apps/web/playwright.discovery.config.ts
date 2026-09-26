import { defineConfig, devices } from "@playwright/test";
export default defineConfig({
  testDir: "./tests", testMatch: ["discovery.spec.ts", "public-home.spec.ts", "carousel-drag.spec.ts"], workers: 1,
  use: { ...devices["Desktop Chrome"], baseURL: "http://127.0.0.1:3209", trace: "retain-on-failure" },
  webServer: [
    { command: "node tests/fixtures/discovery-server.mjs", url: "http://127.0.0.1:8099/__reset", reuseExistingServer: false },
    { command: "npm run start -- --hostname 127.0.0.1 --port 3209", url: "http://127.0.0.1:3209/api/health", env: { API_ORIGIN: "http://127.0.0.1:8099" }, reuseExistingServer: false },
  ],
});
