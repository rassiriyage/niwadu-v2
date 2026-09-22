import { defineConfig, devices } from "@playwright/test";
import path from "node:path";

const apiPath = path.resolve(__dirname, "../api");
const php = process.env.PHP_BIN || "php";

export default defineConfig({
  testDir: "./tests",
  fullyParallel: false,
  workers: 1,
  retries: 0,
  use: { baseURL: "http://127.0.0.1:3101", trace: "retain-on-failure" },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
  webServer: [
    {
      command: `${php} artisan migrate --force --no-interaction && ${php} artisan db:seed --class=HotelAccessTestSeeder --force --no-interaction && ${php} artisan serve --host=127.0.0.1 --port=8101`,
      cwd: apiPath,
      url: "http://127.0.0.1:8101/up",
      reuseExistingServer: false,
      env: { APP_ENV: "testing", DB_CONNECTION: "sqlite", DB_DATABASE: path.join(apiPath, "database/niwadu-browser-tests.sqlite"), DB_URL: "", SESSION_DRIVER: "database", SESSION_COOKIE: "niwadu_browser_test_session", MAIL_MAILER: "log", CACHE_STORE: "database", FRONTEND_URL: "http://127.0.0.1:3101" },
    },
    {
      command: "npm run dev -- --hostname 127.0.0.1 --port 3101",
      url: "http://127.0.0.1:3101/api/health",
      reuseExistingServer: false,
      env: { API_ORIGIN: "http://127.0.0.1:8101" },
    },
  ],
});
