import { execFileSync } from "node:child_process";
import path from "node:path";
import { test, expect, type Page } from "@playwright/test";
test.beforeEach(() => {
  const api = process.env.PLANNING_API_PATH!;
  execFileSync("/opt/homebrew/opt/php@8.4/bin/php", [path.resolve("tests/fixtures/planning-seed.php"), "clear-cache"], { cwd: api, env: { ...process.env, PLANNING_API_PATH: api, APP_ENV: "testing", DB_CONNECTION: "sqlite", DB_DATABASE: path.join(api, "database/planning-browser.sqlite"), DB_URL: "", CACHE_STORE: "database" } });
});
const dated = "/hotels/planning-fixture?arrival=2030-01-10&departure=2030-01-12&adults=2&currency=USD";
async function login(page: Page, email = "planning@example.test") {
  const session = await (await page.request.get("/api/v1/session")).json();
  const response = await page.request.post("/api/v1/login", { data: { email, password: "planning-test-password" }, headers: { "X-CSRF-TOKEN": session.csrf_token } });
  expect(response.status()).toBe(200);
}
test("real dated selection creates an unreserved plan and reopening shows status only", async ({ page }) => {
  await login(page);
  await page.goto(dated);
  await page.getByRole("link", { name: "Review stay plan", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Review stay plan", exact: true })).toBeVisible();
  await expect(page.locator(".planning-panel")).toContainText("Planning fixture");
  await expect(page.getByRole("combobox", { name: "Currency", exact: true })).toHaveCount(0);
  const quoteResponse = page.waitForResponse(response => response.url().endsWith("/booking-quotes"));
  await page.getByRole("button", { name: "Request current quote" }).click();
  expect((await quoteResponse).headers()["cache-control"]).toContain("no-store");
  await expect(page.locator(".planning-quote")).toContainText("USD 220.00");
  await expect(page.locator(".planning-quote")).toContainText("Bed and breakfast (BB)");
  await expect(page.locator(".planning-quote")).toContainText("Nothing reserved");
  await page.getByRole("button", { name: "Save stay plan", exact: true }).click();
  await page.getByRole("link", { name: "Open saved plan status" }).click();
  await expect(page.getByRole("heading", { name: "Saved stay plan" })).toBeVisible();
  await expect(page.locator(".planning-panel")).toContainText("Nothing reserved");
  await expect(page.locator(".planning-panel")).toContainText("Original prices and policies are not available on this status page");
  await expect(page.locator(".planning-panel")).not.toContainText("220.00");
});

test("disabled server routes hide controls and do not request planning APIs", async ({ page }) => {
  let calls = 0;
  page.on("request", request => { if (/\/me\/booking-(quotes|intents)/.test(request.url())) calls++; });
  await page.goto(`http://127.0.0.1:3259${dated}`);
  await expect(page.getByRole("link", { name: "Review stay plan", exact: true })).toHaveCount(0);
  for (const path of ["/plan/review?hotel_slug=planning-fixture&hotel_id=1&rate_plan_id=1&arrival=2030-01-10&departure=2030-01-12&adults=2", "/plan/intents/11111111-1111-4111-8111-111111111111"]) {
    const response = await page.goto(`http://127.0.0.1:3259${path}`);
    expect(response?.status()).toBe(404);
    await expect(page.getByRole("button", { name: "Request current quote" })).toHaveCount(0);
  }
  expect(calls).toBe(0);
});
async function review(page: Page) {
  await login(page); await page.goto(dated);
  await page.getByRole("link", { name: "Review stay plan", exact: true }).click();
  await page.getByRole("button", { name: "Request current quote" }).click();
  await expect(page.locator(".planning-quote")).toContainText("USD 220.00");
}
async function switchAccount(page: Page) {
  const session = await (await page.request.get("/api/v1/session")).json();
  expect((await page.request.post("/api/v1/logout", { headers: { "X-CSRF-TOKEN": session.csrf_token } })).ok()).toBe(true);
  await login(page, "other-planning@example.test");
}
test("uncertain save retains the original key and payload beyond displayed expiry", async ({ page }) => {
  await page.clock.install();
  await review(page);
  const attempts: { key: string | undefined; body: string | null }[] = [];
  let savedId = "";
  await page.route("**/api/v1/me/booking-intents", async route => {
    attempts.push({ key: route.request().headers()["idempotency-key"], body: route.request().postData() });
    const response = await route.fetch();
    const body = await response.json();
    if (attempts.length === 1) { expect(response.status()).toBe(200); savedId = body.data.id; await route.abort("failed"); }
    else { expect(body.data.id).toBe(savedId); await route.fulfill({ response }); }
  });
  await page.getByRole("button", { name: "Save stay plan", exact: true }).click();
  await expect(page.getByRole("button", { name: "Retry original save" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Request current quote" })).toBeDisabled();
  await page.clock.fastForward(65000);
  await page.getByRole("button", { name: "Retry original save" }).click();
  await expect(page.getByRole("link", { name: "Open saved plan status" })).toHaveAttribute("href", `/plan/intents/${savedId}`);
  expect(attempts).toHaveLength(2); expect(attempts[0].key).toBeTruthy(); expect(attempts[1]).toEqual(attempts[0]);
});
test("changed quote preserves selection and requires an explicit fresh review", async ({ page }) => {
  await review(page);
  const selectionURL = page.url();
  let quotes = 0;
  page.on("request", request => { if (request.url().endsWith("/booking-quotes")) quotes++; });
  await page.route("**/api/v1/me/booking-intents", route => route.fulfill({ status: 409, json: { code: "quote_changed", message: "The quote changed. Request a current quote." } }));
  await page.getByRole("button", { name: "Save stay plan", exact: true }).click();
  await expect(page.locator(".planning-panel").getByRole("alert")).toContainText("quote changed");
  await expect(page.locator(".planning-quote")).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Request current quote" })).toBeEnabled();
  expect(page.url()).toBe(selectionURL); expect(quotes).toBe(0);
});
test("account changes clear quotes and another account cannot reopen an owned intent", async ({ page }) => {
  await review(page);
  await page.getByRole("button", { name: "Save stay plan", exact: true }).click();
  const target = await page.getByRole("link", { name: "Open saved plan status" }).getAttribute("href");
  await switchAccount(page);
  await page.evaluate(() => window.dispatchEvent(new Event("focus")));
  await expect(page.locator(".planning-panel").getByRole("alert")).toContainText("account changed");
  await expect(page.getByRole("link", { name: "Open saved plan status" })).toHaveCount(0);
  await page.goto(target!);
  await expect(page.locator(".planning-panel").getByRole("alert")).toContainText("not available to this account");
  await expect(page.getByRole("link", { name: "Sign in", exact: true })).toHaveCount(0);
  await expect(page.locator(".planning-quote")).toHaveCount(0);
});
test("unavailable and expired quotes never enable a new plan save", async ({ page }) => {
  await login(page); await page.goto(dated); await page.getByRole("link", { name: "Review stay plan", exact: true }).click();
  const url = page.url();
  await page.route("**/api/v1/me/booking-quotes", route => route.fulfill({ json: { data: { state: "unavailable", reason: "quote_unavailable", quote: null }, meta: { checkout_enabled: false } } }));
  await page.getByRole("button", { name: "Request current quote" }).click();
  await expect(page.locator(".planning-panel")).toContainText("This selection is unavailable");
  expect(page.url()).toBe(url);
  await page.unroute("**/api/v1/me/booking-quotes");
  await page.route("**/api/v1/me/booking-quotes", async route => {
    const response = await route.fetch(); const body = await response.json(); body.data.quote.expires_at = new Date(Date.now() - 1000).toISOString(); await route.fulfill({ response, json: body });
  });
  await page.getByRole("button", { name: "Request current quote" }).click();
  await expect(page.locator(".planning-panel")).toContainText("This quote has expired");
  await expect(page.getByRole("button", { name: "Save stay plan", exact: true })).toBeDisabled();
  await page.setViewportSize({ width: 320, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test("guest selection waits for authentication without requesting a quote", async ({ page }) => {
  let quotes = 0;
  page.on("request", request => { if (request.url().endsWith("/booking-quotes")) quotes++; });
  await page.goto(dated); await page.getByRole("link", { name: "Review stay plan", exact: true }).click();
  await expect(page.getByRole("link", { name: "Sign in", exact: true })).toHaveAttribute("target", "_blank");
  await expect(page.getByRole("button", { name: "Request current quote" })).toHaveCount(0);
  expect(quotes).toBe(0);
  await login(page); await page.evaluate(() => window.dispatchEvent(new Event("focus")));
  await expect(page.getByRole("button", { name: "Request current quote" })).toBeEnabled();
  expect(quotes).toBe(0);
});
test("rate limiting backs off and CSRF recovery retries the same intent", async ({ page }) => {
  await page.clock.install(); await review(page);
  const attempts: string[] = [];
  await page.route("**/api/v1/me/booking-intents", async route => {
    attempts.push(`${route.request().headers()["idempotency-key"]}:${route.request().postData()}`);
    if (attempts.length === 1) await route.fulfill({ status: 429, headers: { "Retry-After": "2" }, json: { message: "Wait before trying again." } });
    else if (attempts.length === 2) await route.fulfill({ status: 419, json: { message: "Refresh the session and retry." } });
    else await route.continue();
  });
  await page.getByRole("button", { name: "Save stay plan", exact: true }).click();
  await expect(page.getByRole("button", { name: "Retry original save" })).toBeDisabled();
  await page.clock.fastForward(3000);
  await page.getByRole("button", { name: "Retry original save" }).click();
  await expect(page.locator(".planning-panel").getByRole("alert")).toContainText("Refresh the session");
  await page.getByRole("button", { name: "Retry original save" }).click();
  await expect(page.getByRole("link", { name: "Open saved plan status" })).toBeVisible();
  expect(attempts).toHaveLength(3); expect(new Set(attempts).size).toBe(1);
});
test("a late quote cannot reveal private data after the account changes", async ({ page }) => {
  await login(page); await page.goto(dated); await page.getByRole("link", { name: "Review stay plan", exact: true }).click();
  let release!: () => void;
  const held = new Promise<void>(resolve => { release = resolve; });
  let reached!: () => void;
  const started = new Promise<void>(resolve => { reached = resolve; });
  await page.route("**/api/v1/me/booking-quotes", async route => { const response = await route.fetch(); reached(); await held; await route.fulfill({ response }); });
  await page.getByRole("button", { name: "Request current quote" }).click(); await started;
  await switchAccount(page); release();
  await expect(page.locator(".planning-panel").getByRole("alert")).toContainText("account changed");
  await expect(page.locator(".planning-quote")).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Save stay plan", exact: true })).toHaveCount(0);
});

test("transient identity failure hides an uncertain save without losing its original retry pair", async ({ page }) => {
  await review(page);
  const attempts: { key: string | undefined; body: string | null }[] = [];
  let originalId = "";
  await page.route("**/api/v1/me/booking-intents", async route => {
    attempts.push({ key: route.request().headers()["idempotency-key"], body: route.request().postData() });
    const response = await route.fetch();
    const value = await response.json();
    if (attempts.length === 1) { originalId = value.data.id; await route.abort("failed"); }
    else { expect(value.data.id).toBe(originalId); await route.fulfill({ response }); }
  });
  await page.getByRole("button", { name: "Save stay plan", exact: true }).click();
  await expect(page.getByRole("button", { name: "Retry original save" })).toBeVisible();
  await page.route("**/api/v1/session", route => route.fulfill({ status: 503, json: { message: "Temporary identity outage" } }));
  await page.evaluate(() => window.dispatchEvent(new Event("focus")));
  await expect(page.locator(".planning-panel").getByRole("alert")).toContainText("could not be verified");
  await expect(page.locator(".planning-quote")).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Request current quote" })).toHaveCount(0);
  await page.unroute("**/api/v1/session");
  await page.evaluate(() => window.dispatchEvent(new Event("focus")));
  await expect(page.getByRole("button", { name: "Retry original save" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Request current quote" })).toBeDisabled();
  await page.getByRole("button", { name: "Retry original save" }).click();
  await expect(page.getByRole("link", { name: "Open saved plan status" })).toHaveAttribute("href", `/plan/intents/${originalId}`);
  expect(attempts).toHaveLength(2); expect(attempts[1]).toEqual(attempts[0]);
});
