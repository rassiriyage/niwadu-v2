import { defineConfig, devices } from "@playwright/test";
import path from "node:path";
const api = process.env.PLANNING_API_PATH;
if (!api || !api.startsWith("/tmp/niwadu-planning-api.")) throw new Error("Set PLANNING_API_PATH to the isolated exact281b039 archive.");
const php = "/opt/homebrew/opt/php@8.4/bin/php";
export default defineConfig({
  testDir: "./tests", testMatch: "planning.spec.ts", workers: 1, timeout: 60000,
  use: { ...devices["Desktop Chrome"], baseURL: "http://127.0.0.1:3258", trace: "retain-on-failure" },
  webServer: [
    { command: `${php} artisan migrate --force --no-interaction && ${php} ${path.resolve(__dirname, "tests/fixtures/planning-seed.php")} && ${php} artisan serve --host=127.0.0.1 --port=8258`, cwd: api, url: "http://127.0.0.1:8258/up", reuseExistingServer: false, env: { PLANNING_API_PATH: api, APP_ENV: "testing", APP_KEY: "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", DB_CONNECTION: "sqlite", DB_DATABASE: path.join(api, "database/planning-browser.sqlite"), DB_URL: "", BCRYPT_ROUNDS: "4", SESSION_DRIVER: "database", SESSION_COOKIE: "niwadu_planning_test", SESSION_SECURE_COOKIE: "false", MAIL_MAILER: "log", CACHE_STORE: "database", BOOKING_PLANNING_ENABLED: "true" } },
    { command: "npm run start -- --hostname 127.0.0.1 --port 3258", url: "http://127.0.0.1:3258/api/health", reuseExistingServer: false, env: { API_ORIGIN: "http://127.0.0.1:8258", BOOKING_PLANNING_ENABLED: "true" } },
    { command: "npm run start -- --hostname 127.0.0.1 --port 3259", url: "http://127.0.0.1:3259/api/health", reuseExistingServer: false, env: { API_ORIGIN: "http://127.0.0.1:8258", BOOKING_PLANNING_ENABLED: "false" } },
  ],
});
