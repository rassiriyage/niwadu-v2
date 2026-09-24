import { expect, test, type Page } from "@playwright/test";
async function call(page: Page, path: string, method = "GET", data?: unknown) {
  const session = await (await page.request.get("/api/v1/session")).json();
  const response = await page.request.fetch(`/api/v1/${path}`, { method, data, headers: { Accept: "application/json", "X-CSRF-TOKEN": session.csrf_token } });
  expect(response.ok()).toBeTruthy();
  return response.status() === 204 ? null : response.json();
}
async function login(page: Page, email = "admin@example.test") {
  await page.goto("/admin"); await page.getByLabel("Email address").fill(email); await page.getByLabel("Password", { exact: true }).fill("browser-test-password"); await page.getByRole("button", { name: "Sign in", exact: true }).click(); await expect(page.getByRole("heading", { name: "Hotels", exact: true })).toBeVisible();
}
async function hotel(page: Page) { await login(page, `operations${["administrator configures", "real stale", "viewer cannot", "PMS credentials", "session expiry", "ambiguous write", "inventory manager", "unsaved form"].findIndex(prefix => test.info().title.startsWith(prefix))}@example.test`); return (await call(page, "hotels", "POST", { name: `Operations fixture ${Date.now()}`, city: "Galle" })).data.id as number; }
async function room(page: Page, id: number) { return (await call(page, `hotels/${id}/room-types`, "POST", { name: "Double room", max_occupancy: 2, status: "active" })).data; }

test("administrator configures room, ownership, policy, stock and explicit money; persists on reload", async ({ page }) => {
  const errors: string[] = []; page.on("pageerror", e => errors.push(e.message));
  const id = await hotel(page); await page.goto(`/admin/hotels/${id}/inventory`);
  await page.getByRole("button", { name: "Add room type", exact: true }).click();
  await page.getByLabel("Room type name").fill("Garden double"); await page.getByLabel("Maximum guests per room").fill("2"); await page.getByRole("combobox", { name: "Status", exact: true }).selectOption("active"); await page.getByRole("button", { name: "Create room type", exact: true }).click();
  await page.getByRole("button", { name: "2. Inventory ownership", exact: true }).click(); await page.getByLabel("I confirm Niwadu").check(); await page.getByLabel("Sales state").selectOption("open"); await page.getByRole("button", { name: "Save manual inventory configuration" }).click(); await expect(page.getByText("Current ownership:")).toContainText("manual");
  await page.getByRole("button", { name: "3. Rate plans", exact: true }).click(); await page.getByLabel("Rate plan name").fill("Room only"); await page.getByRole("combobox", { name: "Status", exact: true }).selectOption("active"); await page.getByLabel("Policy revision label").fill("v1"); await page.getByLabel("Cancellation and rate policy").fill("Cancel 48 hours before arrival."); await page.getByRole("button", { name: "Create rate plan", exact: true }).click(); await expect(page.getByRole("button", { name: "Save rate plan", exact: true })).toBeVisible();
  await page.getByRole("button", { name: "4. Dated stock and prices" }).click(); await page.getByLabel("Total allocated room capacity").fill("5"); await page.getByRole("button", { name: "Save stock for this date" }).click(); await expect(page.locator(".ops-form[data-unsaved]")).toHaveCount(0); await expect(page.getByLabel("Total allocated room capacity")).toHaveValue("5");
  await page.getByRole("button", { name: "Price and restrictions", exact: true }).click();
  for (const [label, value] of [["Base price (LKR)", "12000.50"], ["Tax (LKR)", "1500"], ["Mandatory fees (LKR)", "0"], ["Minimum stay (nights)", "1"], ["Maximum stay (nights)", "30"]]) await page.getByLabel(label, { exact: true }).fill(value);
  await page.getByLabel("All mandatory charges are included").check(); await page.getByLabel("Stop sales for this date").uncheck(); await page.getByRole("button", { name: "Save price and restrictions", exact: true }).click(); await expect(page.getByLabel("Base price (LKR)", { exact: true })).toHaveValue("12000.50");
  await expect(page.locator(".ops-form[data-unsaved]")).toHaveCount(0); await page.reload(); await page.getByRole("button", { name: "Garden double", exact: true }).click(); await page.getByRole("button", { name: "4. Dated stock and prices" }).click(); await page.getByRole("button", { name: "Price and restrictions", exact: true }).click(); await expect(page.getByLabel("Tax (LKR)", { exact: true })).toHaveValue("1500.00"); for (const width of [320, 768, 1024, 1440]) { await page.setViewportSize({ width, height: 900 }); await page.screenshot({ path: `test-results/operations-${width}.png`, fullPage: true }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); }
  expect(errors).toEqual([]);
});

test("real stale room save preserves inputs and requires explicit discard/reload", async ({ page }) => {
  const id = await hotel(page), r = await room(page, id); await page.goto(`/admin/hotels/${id}/inventory`); await page.getByRole("button", { name: "Double room", exact: true }).click();
  await call(page, `hotels/${id}/room-types/${r.id}`, "PUT", { name: "Other editor", max_occupancy: 2, status: "active", version: r.version });
  await page.getByLabel("Room type name").fill("My unsaved room"); await page.getByRole("button", { name: "Save room details", exact: true }).click(); await expect(page.locator(".ops-form").getByRole("alert")).toContainText("changed"); await expect(page.getByLabel("Room type name")).toHaveValue("My unsaved room"); await expect(page.getByRole("button", { name: "Save room details", exact: true })).toBeDisabled();
  page.once("dialog", d => d.accept()); await page.getByRole("button", { name: "Discard edits and reload latest" }).click(); await expect(page.getByLabel("Room type name")).toHaveValue("Other editor");
});

test("viewer cannot edit or see PMS settings; mobile layout stays within viewport", async ({ page }) => {
  const id = await hotel(page); await room(page, id); await call(page, `hotels/${id}/staff`, "POST", { name: "Test Manager", email: "manager@example.test", role: "viewer" }); await call(page, "logout", "POST"); await login(page, "manager@example.test"); await page.setViewportSize({ width: 320, height: 800 }); await page.goto(`/admin/hotels/${id}/inventory`);
  await expect(page.getByRole("button", { name: "PMS settings", exact: true })).toHaveCount(0); await expect(page.getByRole("button", { name: "Add room type", exact: true })).toHaveCount(0); await page.getByRole("button", { name: "Double room", exact: true }).click(); await expect(page.getByLabel("Room type name")).toBeDisabled(); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test("PMS credentials are masked, saved redacted and cleared; configuration is not live verification", async ({ page }) => {
  const id = await hotel(page); await page.goto(`/admin/hotels/${id}/inventory`); await page.getByRole("button", { name: "PMS settings", exact: true }).click(); await page.getByLabel("Provider property ID").fill("fixture-property"); await page.getByLabel("HTTPS endpoint origin").fill("https://pms.example.test"); await page.getByLabel("Credential 1 name").fill("api_key"); await page.getByLabel("Credential 1 value").fill("synthetic-secret-only"); await expect(page.getByLabel("Credential 1 value")).toHaveAttribute("type", "password"); await page.getByRole("button", { name: "Save PMS settings", exact: true }).click(); await expect(page.getByRole("heading", { name: "Edit saved configuration" })).toBeVisible(); await expect(page.getByLabel("Credential 1 value")).toHaveValue(""); await expect(page.getByText("Never verified", { exact: true })).toBeVisible(); const result = await call(page, `hotels/${id}/pms-connections`); expect(JSON.stringify(result)).not.toContain("synthetic-secret-only"); expect(result.data[0].enabled).toBe(false);
});

test("session expiry clears private forms and prevents a write", async ({ page }) => {
  const id = await hotel(page); await room(page, id); await page.goto(`/admin/hotels/${id}/inventory`); await page.getByRole("button", { name: "Double room", exact: true }).click(); await page.getByLabel("Room type name").fill("Unsaved"); await call(page, "logout", "POST"); await page.getByRole("button", { name: "Save room details", exact: true }).click(); await expect(page.getByRole("heading", { name: "Sign in again", exact: true })).toBeVisible(); await expect(page.getByLabel("Room type name")).toHaveCount(0);
});

test("ambiguous write stays blocked until explicit reload resets unchanged-version inputs", async ({ page }) => {
  const id = await hotel(page); await room(page, id); await page.goto(`/admin/hotels/${id}/inventory`); await page.getByRole("button", { name: "Double room", exact: true }).click();
  let writes = 0;
  await page.route("**/api/v1/hotels/*/room-types/*", async route => { if (route.request().method() === "PUT") { writes++; await route.abort("failed"); } else await route.continue(); });
  await page.getByLabel("Room type name").fill("Uncertain draft"); await page.getByRole("button", { name: "Save room details", exact: true }).click(); await expect(page.getByRole("button", { name: "Discard edits and reload latest" })).toBeVisible(); await expect(page.getByRole("button", { name: "Save room details", exact: true })).toBeDisabled(); expect(writes).toBe(1); await expect(page.getByLabel("Room type name")).toHaveValue("Uncertain draft");
  page.once("dialog", d => d.accept()); await page.getByRole("button", { name: "Discard edits and reload latest" }).click(); await expect(page.getByLabel("Room type name")).toHaveValue("Double room"); expect(writes).toBe(1);
});

test("inventory manager edits stock without room, ownership or PMS powers", async ({ page }) => {
  const id = await hotel(page), r = await room(page, id); await call(page, `hotels/${id}/room-types/${r.id}/inventory-pool`, "PUT", { owner: "manual", sales_state: "closed", timezone: "Asia/Colombo", version: 0 }); await call(page, `hotels/${id}/staff`, "POST", { name: "Test Manager", email: "manager@example.test", role: "inventory_manager" }); await call(page, "logout", "POST"); await login(page, "manager@example.test"); await page.goto(`/admin/hotels/${id}/inventory`); await page.getByRole("button", { name: "Double room", exact: true }).click(); await expect(page.getByLabel("Room type name")).toBeDisabled(); await page.getByRole("button", { name: "2. Inventory ownership" }).click(); await expect(page.getByLabel("Hotel timezone (IANA name)")).toBeDisabled(); await page.getByRole("button", { name: "4. Dated stock and prices" }).click(); await page.getByLabel("Total allocated room capacity").fill("4"); await page.getByRole("button", { name: "Save stock for this date" }).click(); await expect(page.locator(".ops-form[data-unsaved]")).toHaveCount(0); await expect(page.getByRole("button", { name: "PMS settings", exact: true })).toHaveCount(0);
});

test("unsaved form navigation can be cancelled and keyboard focus remains usable", async ({ page }) => {
  const id = await hotel(page); await room(page, id); await page.goto(`/admin/hotels/${id}/inventory`); await page.getByRole("button", { name: "Double room", exact: true }).click(); await page.getByLabel("Room type name").fill("Keep my draft"); page.once("dialog", d => d.dismiss()); await page.getByRole("button", { name: "2. Inventory ownership" }).click(); await expect(page.getByLabel("Room type name")).toHaveValue("Keep my draft"); await page.getByLabel("Room type name").focus(); await page.keyboard.press("Tab"); await expect(page.getByLabel("Maximum guests per room")).toBeFocused();
});
