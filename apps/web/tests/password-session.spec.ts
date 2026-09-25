import { expect, test, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import path from "node:path";

async function draft(page: Page) {
  await page.goto("/admin");
  await page.getByLabel("Email address").fill("employee@example.test");
  await page.getByLabel("Password", { exact: true }).fill("browser-test-password");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page.getByRole("button", { name: "Add hotel" }).click();
  await page.getByLabel("Hotel name").fill(`QA regression ${Date.now()}`);
  await page.getByRole("button", { name: "Create draft" }).click();
  await expect(page.getByRole("heading", { name: "Hotel basics", exact: true })).toBeVisible({ timeout: 15000 });
  return page.url().match(/hotels\/(\d+)/)![1];
}

test("resetting a staff password invalidates an already authenticated session", async ({ page, browser, baseURL }) => {
  const hotelId = await draft(page);
  const email = `qa-session-${Date.now()}@example.test`;
  const session = await (await page.request.get("/api/v1/session")).json();
  const headers = { "X-CSRF-TOKEN": session.csrf_token, Accept: "application/json" };
  const granted = await page.request.post(`/api/v1/hotels/${hotelId}/staff`, {
    headers, data: { name: "QA session user", email, role: "viewer" },
  });
  expect(granted.status()).toBe(201);
  const memberId = (await granted.json()).data.id;
  const latestToken = () => {
    const log = readFileSync(path.join(process.env.WIZARD_API_PATH || path.resolve(__dirname, "../../api"), "storage/logs/laravel.log"), "utf8");
    return [...log.matchAll(/\/admin\/password#token=([a-zA-Z0-9]+)&email=/g)].at(-1)![1];
  };
  const client = await browser.newContext({ baseURL });
  try {
    const csrf = async () => (await (await client.request.get("/api/v1/session")).json()).csrf_token;
    const reset = async (password: string) => {
      const response = await client.request.post("/api/v1/password/setup", {
        headers: { "X-CSRF-TOKEN": await csrf(), Accept: "application/json" },
        data: { email, token: latestToken(), password, password_confirmation: password },
      });
      expect(response.ok()).toBe(true);
    };
    await reset("initial-qa-password");
    const login = await client.request.post("/api/v1/login", {
      headers: { "X-CSRF-TOKEN": await csrf(), Accept: "application/json" },
      data: { email, password: "initial-qa-password" },
    });
    expect(login.ok()).toBe(true);
    expect((await (await client.request.get("/api/v1/session")).json()).user.email).toBe(email);
    expect((await page.request.post(`/api/v1/hotels/${hotelId}/staff/${memberId}/password-link`, { headers })).ok()).toBe(true);
    // Reset through an independent request context so the existing login is not reused.
    const response = await page.request.post("/api/v1/password/setup", {
      headers, data: { email, token: latestToken(), password: "replacement-qa-password", password_confirmation: "replacement-qa-password" },
    });
    expect(response.ok()).toBe(true);
    expect((await client.request.get("/api/v1/session")).status()).toBe(401);
    expect((await client.request.get("/api/v1/hotels")).status()).toBe(401);
    expect((await (await client.request.get("/api/v1/session")).json()).user).toBeNull();
    expect((await (await page.request.get("/api/v1/session")).json()).user.email).toBe("employee@example.test");
    const usedToken = latestToken();
    const reused = await client.request.post("/api/v1/password/setup", {
      headers: { "X-CSRF-TOKEN": await csrf(), Accept: "application/json" },
      data: { email, token: usedToken, password: "replacement-qa-password", password_confirmation: "replacement-qa-password" },
    });
    expect(reused.status()).toBe(422);
    const oldLogin = await client.request.post("/api/v1/login", {
      headers: { "X-CSRF-TOKEN": await csrf(), Accept: "application/json" }, data: { email, password: "initial-qa-password" },
    });
    expect(oldLogin.status()).toBe(422);
    const newLogin = await client.request.post("/api/v1/login", {
      headers: { "X-CSRF-TOKEN": await csrf(), Accept: "application/json" }, data: { email, password: "replacement-qa-password" },
    });
    expect(newLogin.status()).toBe(200);
    expect((await (await client.request.get("/api/v1/session")).json()).user.email).toBe(email);
  } finally {
    await client.close();
  }
});
