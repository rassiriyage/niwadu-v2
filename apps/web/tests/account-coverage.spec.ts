import { expect, test, type Page } from "@playwright/test";

async function mockAccount(page: Page, signedIn = true) {
  const state = {
    user: signedIn ? { id: 11, name: "QA Traveller", email: "traveller@example.test" } as { id: number; name: string; email: string } | null : null,
    coverage: { districts: ["colombo"], version: 1 }, writes: [] as { path: string; body: Record<string, unknown> }[],
    conflict: false, unavailable: false, expireOnWrite: false, loseSaveResponse: false,
  };
  await page.route("**/api/v1/**", async route => {
    const request = route.request(); const path = new URL(request.url()).pathname.replace("/api/v1/", "");
    const reply = (status: number, json: unknown) => route.fulfill({ status, json, headers: { "cache-control": "no-store" } });
    if (state.unavailable) return reply(503, { message: "Unavailable" });
    if (path === "session") return reply(200, { user: state.user, csrf_token: "qa-csrf-token" });
    if (request.method() !== "GET") {
      expect(request.headers()["x-csrf-token"]).toBe("qa-csrf-token");
      state.writes.push({ path, body: request.postDataJSON() || {} });
    }
    if (path === "register" && request.postDataJSON().password !== request.postDataJSON().password_confirmation) return reply(422, { message: "Passwords must match.", errors: { password_confirmation: ["Passwords must match."] } });
    if (path === "register" || path === "login") {
      state.user = { id: 11, name: "QA Traveller", email: "traveller@example.test" };
      return reply(path === "register" ? 201 : 200, { user: state.user, csrf_token: "qa-csrf-token" });
    }
    if (!state.user) return reply(401, { message: "Unauthenticated" });
    if (path === "logout") { state.user = null; return route.fulfill({ status: 204 }); }
    if (path === "me/coverage" && request.method() === "PUT") {
      if (state.expireOnWrite) { state.user = null; return reply(401, { message: "Unauthenticated" }); }
      const body = request.postDataJSON();
      if (state.conflict || body.version !== state.coverage.version) return reply(409, { message: "Coverage changed" });
      state.coverage = { districts: body.districts, version: state.coverage.version + 1 };
      if (state.loseSaveResponse) return route.abort();
    }
    return reply(200, state.coverage);
  });
  return state;
}

test("coverage saves explicitly to account and reloads without browser storage", async ({ page }) => {
  const state = await mockAccount(page);
  await page.goto("/cover");
  await expect(page.getByRole("checkbox", { name: "Colombo", exact: true })).toBeChecked();
  await page.getByRole("checkbox", { name: "Kandy", exact: true }).check();
  expect(state.writes).toHaveLength(0);
  await expect(page.getByRole("status")).toContainText("Unsaved changes");
  await page.getByRole("button", { name: "Save map", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("saved to your account");
  expect(state.writes[0].body).toEqual({ districts: ["colombo", "kandy"], version: 1 });
  await page.reload();
  await expect(page.getByRole("checkbox", { name: "Kandy", exact: true })).toBeChecked();
  expect(await page.evaluate(() => Object.keys(localStorage))).toEqual([]);
});

test("conflict preserves selections and requires explicit resolution before another save", async ({ page }) => {
  const state = await mockAccount(page);
  await page.goto("/cover");
  await page.getByRole("checkbox", { name: "Kandy", exact: true }).check();
  state.coverage = { districts: ["galle"], version: 2 }; state.conflict = true;
  await page.getByRole("button", { name: "Save map", exact: true }).click();
  await expect(page.locator(".account-error")).toContainText("changed elsewhere");
  await expect(page.getByRole("checkbox", { name: "Kandy", exact: true })).toBeChecked();
  await expect(page.getByRole("button", { name: "Save map", exact: true })).toBeDisabled();
  await page.getByRole("button", { name: "Compare saved map" }).click();
  await expect(page.getByRole("region", { name: "Resolve map changes" })).toContainText("Galle");
  expect(state.writes).toHaveLength(1);
  await page.getByRole("button", { name: "Keep my selections" }).click();
  expect(state.writes).toHaveLength(1);
  state.conflict = false;
  await page.getByRole("button", { name: "Save map", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("saved to your account");
  expect(state.writes[1].body).toEqual({ districts: ["colombo", "kandy"], version: 2 });
});

test("changed account prevents stale coverage writes and clears private state", async ({ page }) => {
  const state = await mockAccount(page);
  await page.goto("/cover");
  await page.getByRole("checkbox", { name: "Kandy", exact: true }).check();
  state.user = { id: 22, name: "Other traveller", email: "other@example.test" };
  await page.getByRole("button", { name: "Save map", exact: true }).click();
  await expect(page.getByRole("link", { name: "Sign in", exact: true })).toBeVisible();
  await expect(page.getByRole("checkbox")).toHaveCount(0);
  expect(state.writes).toHaveLength(0);
});

test("registration posts only public fields and opens account-backed map", async ({ page }) => {
  const state = await mockAccount(page, false);
  await page.goto("/account?mode=register");
  await page.getByLabel("Your name", { exact: true }).fill("QA Traveller");
  await page.getByLabel("Email address", { exact: true }).fill("traveller@example.test");
  await page.getByLabel("Password", { exact: true }).fill("Synthetic-test-password-42");
  await page.getByLabel("Confirm password", { exact: true }).fill("Synthetic-test-password-42");
  await page.getByRole("button", { name: "Create account", exact: true }).click();
  await expect(page).toHaveURL(/\/cover$/);
  await expect(page.getByRole("checkbox", { name: "Colombo", exact: true })).toBeChecked();
  expect(Object.keys(state.writes[0].body).sort()).toEqual(["email", "name", "password", "password_confirmation"]);
  await page.getByRole("link", { name: "Your account", exact: true }).click();
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Welcome back" })).toBeVisible();
  expect(state.user).toBeNull();
});

test("mobile map, empty selection reset and unavailable service recover honestly", async ({ page }) => {
  const state = await mockAccount(page);
  await page.setViewportSize({ width: 320, height: 844 });
  await page.goto("/cover");
  await page.getByRole("button", { name: "Clear all districts" }).click();
  await page.getByRole("button", { name: "Keep districts" }).click();
  await expect(page.getByRole("checkbox", { name: "Colombo", exact: true })).toBeChecked();
  await page.getByRole("button", { name: "Clear all districts" }).click();
  await page.getByRole("button", { name: "Clear selections", exact: true }).click();
  await page.getByRole("button", { name: "Save map", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("saved to your account");
  expect(state.coverage.districts).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  state.unavailable = true;
  await page.reload();
  await expect(page.locator(".account-error")).toBeVisible();
  await expect(page.getByRole("checkbox")).toHaveCount(0);
  state.unavailable = false;
  await page.getByRole("button", { name: "Reload map" }).click();
  await expect(page.getByRole("checkbox")).toHaveCount(25);
});


test("expired write clears account map and ambiguous response requires reread", async ({ page }) => {
  const state = await mockAccount(page);
  await page.goto("/cover");
  await page.getByRole("checkbox", { name: "Kandy", exact: true }).check();
  state.loseSaveResponse = true;
  await page.getByRole("button", { name: "Save map", exact: true }).click();
  await expect(page.locator(".account-error")).toContainText("could not confirm this save");
  await expect(page.getByRole("checkbox", { name: "Kandy", exact: true })).toBeChecked();
  await page.getByRole("button", { name: "Compare saved map" }).click();
  await page.getByRole("button", { name: "Use saved map" }).click();
  await expect(page.getByRole("button", { name: "Save map", exact: true })).toBeDisabled();
  expect(state.writes).toHaveLength(1);
  state.loseSaveResponse = false; state.expireOnWrite = true;
  await page.getByRole("checkbox", { name: "Galle", exact: true }).check();
  await page.getByRole("button", { name: "Save map", exact: true }).click();
  await expect(page.getByRole("checkbox")).toHaveCount(0);
  await expect(page.locator(".account-error")).toContainText("session ended");
});

test("registration validation stays on form without claiming an account", async ({ page }) => {
  await mockAccount(page, false);
  await page.goto("/account?mode=register");
  await page.getByLabel("Your name", { exact: true }).fill("QA Traveller");
  await page.getByLabel("Email address", { exact: true }).fill("traveller@example.test");
  await page.getByLabel("Password", { exact: true }).fill("Synthetic-test-password-42");
  await page.getByLabel("Confirm password", { exact: true }).fill("Different-test-password-43");
  await page.getByRole("button", { name: "Create account", exact: true }).click();
  await expect(page.locator(".account-field-error")).toHaveText("Passwords must match.");
  await expect(page).toHaveURL(/account\?mode=register$/);
});
