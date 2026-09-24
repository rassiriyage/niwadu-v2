import { defineConfig, devices } from "@playwright/test";
import path from "node:path";
const apiPath = process.env.OPERATIONS_API_PATH || path.resolve(__dirname, "../../../api-operations/apps/api");
const php = process.env.PHP_BIN || "/opt/homebrew/opt/php@8.4/bin/php";
export default defineConfig({
  testDir: "./tests", testMatch: "operations.spec.ts", workers: 1, retries: 0,
  use: { baseURL: "http://127.0.0.1:3233", trace: "retain-on-failure" },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
  webServer: [
    { command: `${php} artisan migrate --force --no-interaction && ${php} artisan db:seed --class=OperationsBrowserTestSeeder --force --no-interaction && ${php} artisan serve --host=127.0.0.1 --port=8233`, cwd: apiPath, url: "http://127.0.0.1:8233/up", reuseExistingServer: false, env: { APP_ENV: "testing", BCRYPT_ROUNDS: "4", APP_KEY: "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", DB_CONNECTION: "sqlite", DB_DATABASE: path.join(apiPath, "database/niwadu-browser-tests.sqlite"), DB_URL: "", SESSION_DRIVER: "database", SESSION_COOKIE: "niwadu_operations_test", SESSION_SECURE_COOKIE: "false", MAIL_MAILER: "log", CACHE_STORE: "database", FRONTEND_URL: "http://127.0.0.1:3233" } },
    { command: "npm run start -- --hostname 127.0.0.1 --port 3233", url: "http://127.0.0.1:3233/api/health", reuseExistingServer: false, env: { API_ORIGIN: "http://127.0.0.1:8233" } },
  ],
});
