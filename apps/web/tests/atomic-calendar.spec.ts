import { expect, test, type Page } from "@playwright/test";

async function call(page: Page, path: string, method = "GET", data?: unknown) {
  const session = await (await page.request.get("/api/v1/session")).json();
  const response = await page.request.fetch(`/api/v1/${path}`, { method, data, headers: { Accept: "application/json", "X-CSRF-TOKEN": session.csrf_token } });
  expect(response.ok()).toBe(true);
  return response.status() === 204 ? null : response.json();
}
async function login(page: Page, email: string) {
  await page.goto("/admin");
  await page.getByLabel("Email address").fill(email);
  await page.getByLabel("Password", { exact: true }).fill("browser-test-password");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Hotels", exact: true })).toBeVisible();
}
async function fixture(page: Page, account: number) {
  await login(page, `operations${account}@example.test`);
  const hotel = (await call(page, "hotels", "POST", { name: `Atomic calendar ${Date.now()}` })).data;
  const room = (await call(page, `hotels/${hotel.id}/room-types`, "POST", { name: "Calendar double", max_occupancy: 2, status: "active" })).data;
  const root = `hotels/${hotel.id}/room-types/${room.id}`;
  await call(page, `${root}/inventory-pool`, "PUT", { version: 0, owner: "manual", sales_state: "closed", timezone: "Asia/Colombo" });
  const dates = Array.from({ length: 7 }, (_, day) => { const date = new Date(); date.setUTCDate(date.getUTCDate() + day); return date.toISOString().slice(0, 10); });
  const to = new Date(); to.setUTCDate(to.getUTCDate() + 7);
  return { hotel, root, dates, query: `from=${dates[0]}&to=${to.toISOString().slice(0, 10)}` };
}
async function openCalendar(page: Page, id: number) {
  await page.goto(`/admin/hotels/${id}/inventory`);
  await page.getByRole("button", { name: "Calendar double", exact: true }).click();
  await page.getByRole("button", { name: "4. Dated stock and prices" }).click();
  await expect(page.getByLabel("Total allocated room capacity")).toBeVisible();
}
const batchSave = (page: Page, kind: "stock" | "prices", count = 2) => page.getByRole("button", { name: `Save ${kind} for ${count} dates`, exact: true });

test("stock batch sends nonadjacent dates and each loaded version in one request", async ({ page }, testInfo) => {
  const { hotel, root, dates, query } = await fixture(page, 0);
  await call(page, `${root}/inventory-nights/${dates[0]}`, "PUT", { version: 0, capacity: 3 });
  await openCalendar(page, hotel.id);
  await page.getByLabel(`Include ${dates[2]}`).focus();
  await page.keyboard.press("Space");
  await expect(page.getByLabel(`Include ${dates[2]}`)).toBeChecked();
  await page.getByLabel(`Include ${dates[5]}`).check();
  const writes: unknown[] = [];
  page.on("request", request => { if (request.method() === "PUT" && request.url().endsWith(`${root}/inventory-nights`)) writes.push(request.postDataJSON()); });
  await page.getByLabel("Total allocated room capacity").fill("6");
  page.once("dialog", dialog => dialog.dismiss());
  await page.getByLabel(`Include ${dates[3]}`).click();
  await expect(page.getByLabel(`Include ${dates[3]}`)).not.toBeChecked();
  await expect(page.getByLabel("Total allocated room capacity")).toHaveValue("6");
  await batchSave(page, "stock", 3).click();
  await expect(page.locator(".ops-form[data-unsaved]")).toHaveCount(0);
  expect(writes).toEqual([{ nights: [0, 2, 5].map(day => ({ stay_date: dates[day], version: day === 0 ? 1 : 0, capacity: 6 })) }]);
  const rows = (await call(page, `${root}/inventory-nights?${query}`)).data;
  expect(rows.filter((row: { configured: boolean }) => row.configured).map((row: { stay_date: string; capacity: number }) => [row.stay_date, row.capacity])).toEqual([0, 2, 5].map(day => [dates[day], 6]));
  for (const width of [320, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const target = await page.locator(".calendar-select").first().boundingBox();
    expect(target?.width).toBeGreaterThanOrEqual(44);
    expect(target?.height).toBeGreaterThanOrEqual(44);
    await page.screenshot({ path: testInfo.outputPath(`calendar-${width}.png`), fullPage: true });
  }
});

test("rate batch edits one USD plan with exact money and unknown charges without changing LKR or stock", async ({ page }) => {
  const { hotel, root, dates, query } = await fixture(page, 1);
  const create = async (currency: string) => (await call(page, `${root}/rate-plans`, "POST", { name: `Half board ${currency}`, currency, meal_plan: "HB", status: "draft", policy: { version: null, text: null } })).data;
  const lkr = await create("LKR"), usd = await create("USD");
  await openCalendar(page, hotel.id);
  await page.getByRole("combobox", { name: "Rate plan", exact: true }).selectOption(String(usd.id));
  await page.getByLabel(`Include ${dates[1]}`).check();
  await page.getByRole("button", { name: "Price and restrictions", exact: true }).click();
  await page.getByLabel("Base price (USD)").fill("31.29");
  await page.getByLabel("Mandatory fees (USD)").fill("0");
  await page.getByLabel("Minimum stay (nights)").fill("1");
  await page.getByLabel("Maximum stay (nights)").fill("30");
  await batchSave(page, "prices").click();
  await expect(page.locator(".ops-form[data-unsaved]")).toHaveCount(0);
  const rows = (await call(page, `${root}/rate-plans/${usd.id}/nights?${query}`)).data.filter((row: { configured: boolean }) => row.configured);
  expect(rows).toHaveLength(2);
  for (const row of rows) { expect(row.base_minor).toBe(3129); expect(row.tax_minor).toBeNull(); expect(row.fee_minor).toBe(0); expect(Boolean(row.mandatory_charges_complete)).toBe(false); }
  expect((await call(page, `${root}/rate-plans/${lkr.id}/nights?${query}`)).data.every((row: { configured: boolean }) => !row.configured)).toBe(true);
  expect((await call(page, `${root}/inventory-nights?${query}`)).data.every((row: { configured: boolean }) => !row.configured)).toBe(true);
});

test("late-date version conflict rolls back the entire stock batch and requires explicit reload", async ({ page }) => {
  const { hotel, root, dates, query } = await fixture(page, 2);
  await openCalendar(page, hotel.id);
  await page.getByLabel(`Include ${dates[1]}`).check();
  await call(page, `${root}/inventory-nights/${dates[1]}`, "PUT", { version: 0, capacity: 9 });
  await page.getByLabel("Total allocated room capacity").fill("6");
  await batchSave(page, "stock").click();
  await expect(batchSave(page, "stock")).toBeDisabled();
  await expect(page.getByLabel("Total allocated room capacity")).toHaveValue("6");
  const rows = (await call(page, `${root}/inventory-nights?${query}`)).data;
  expect(rows[0].configured).toBe(false); expect(rows[1].capacity).toBe(9);
  page.once("dialog", dialog => dialog.accept());
  await page.getByRole("button", { name: "Discard edits and reload latest" }).click();
  await expect(batchSave(page, "stock")).toBeEnabled();
  await expect(page.getByLabel("Total allocated room capacity")).toHaveValue("");
});

for (const failure of ["disconnect", "timeout"]) test(`lost batch response (${failure}) blocks replay until GET reconciliation and then uses the new versions`, async ({ page }) => {
  const { hotel, root, dates } = await fixture(page, 3);
  await openCalendar(page, hotel.id);
  await page.getByLabel(`Include ${dates[1]}`).check();
  const writes: { nights: { version: number }[] }[] = [];
  await page.route(`**/api/v1/${root}/inventory-nights`, async route => {
    if (route.request().method() !== "PUT") return route.continue();
    writes.push(route.request().postDataJSON());
    if (writes.length === 1) {
      await route.fetch();
      if (failure === "disconnect") await route.abort("failed");
      else await route.fulfill({ status: 408, json: { message: "Response timed out" } });
    } else await route.continue();
  });
  await page.getByLabel("Total allocated room capacity").fill("6");
  await batchSave(page, "stock").click();
  await expect(batchSave(page, "stock")).toBeDisabled();
  expect(writes).toHaveLength(1);
  await expect(page.getByLabel("Total allocated room capacity")).toHaveValue("6");
  page.once("dialog", dialog => dialog.accept());
  await page.getByLabel(`Include ${dates[2]}`).click();
  await expect(page.getByLabel(`Include ${dates[2]}`)).not.toBeChecked();
  await page.route(`**/api/v1/${root}/inventory-nights?*`, route => route.fulfill({ status: 503, json: { message: "Calendar unavailable" } }));
  page.once("dialog", dialog => dialog.accept());
  await page.getByRole("button", { name: "Discard edits and reload latest" }).click();
  await expect(page.locator(".ops-form").getByRole("alert")).toContainText("Calendar unavailable");
  await expect(batchSave(page, "stock")).toBeDisabled();
  await expect(page.getByLabel("Total allocated room capacity")).toHaveValue("6");
  await page.unroute(`**/api/v1/${root}/inventory-nights?*`);
  page.once("dialog", dialog => dialog.accept());
  await page.getByRole("button", { name: "Discard edits and reload latest" }).click();
  await expect(batchSave(page, "stock")).toBeEnabled();
  expect(writes).toHaveLength(1);
  await page.getByLabel("Total allocated room capacity").fill("7");
  await batchSave(page, "stock").click();
  await expect(page.locator(".ops-form[data-unsaved]")).toHaveCount(0);
  expect(writes.map(write => write.nights.map(night => night.version))).toEqual([[0, 0], [1, 1]]);
});

test("invalid rate batch retains edits and saves no dates until corrected", async ({ page }) => {
  const { hotel, root, dates, query } = await fixture(page, 4);
  const plan = (await call(page, `${root}/rate-plans`, "POST", { name: "Draft LKR", currency: "LKR", meal_plan: "RO", status: "draft", policy: { version: null, text: null } })).data;
  await openCalendar(page, hotel.id);
  await page.getByLabel(`Include ${dates[1]}`).check();
  await page.getByRole("button", { name: "Price and restrictions", exact: true }).click();
  await page.getByLabel("Base price (LKR)").fill("200");
  await page.getByLabel("Minimum stay (nights)").fill("5");
  await page.getByLabel("Maximum stay (nights)").fill("2");
  await batchSave(page, "prices").click();
  await expect(page.locator(".ops-form").getByRole("alert")).toBeVisible();
  expect((await call(page, `${root}/rate-plans/${plan.id}/nights?${query}`)).data.every((row: { configured: boolean }) => !row.configured)).toBe(true);
  await expect(page.getByLabel("Base price (LKR)")).toHaveValue("200");
  await page.getByLabel("Maximum stay (nights)").fill("10");
  await batchSave(page, "prices").click();
  await expect(page.locator(".ops-form[data-unsaved]")).toHaveCount(0);
  expect((await call(page, `${root}/rate-plans/${plan.id}/nights?${query}`)).data.filter((row: { configured: boolean }) => row.configured)).toHaveLength(2);
});

test("inventory manager can batch stock but a viewer cannot select dates or save", async ({ page }) => {
  const { hotel, dates } = await fixture(page, 5);
  await call(page, `hotels/${hotel.id}/staff`, "POST", { name: "Test Manager", email: "manager@example.test", role: "inventory_manager" });
  await call(page, "logout", "POST"); await login(page, "manager@example.test");
  await openCalendar(page, hotel.id);
  await page.getByLabel(`Include ${dates[1]}`).check();
  await page.getByLabel("Total allocated room capacity").fill("4");
  await batchSave(page, "stock").click();
  await expect(page.locator(".ops-form[data-unsaved]")).toHaveCount(0);
  await expect(page.getByRole("button", { name: "PMS settings", exact: true })).toHaveCount(0);
  await call(page, "logout", "POST"); await login(page, "operations5@example.test");
  await call(page, `hotels/${hotel.id}/staff`, "POST", { name: "Test Manager", email: "manager@example.test", role: "viewer" });
  await call(page, "logout", "POST"); await login(page, "manager@example.test");
  await openCalendar(page, hotel.id);
  await expect(page.getByLabel(`Include ${dates[1]}`)).toHaveCount(0);
  await expect(page.getByLabel("Total allocated room capacity")).toBeDisabled();
  await expect(page.getByRole("button", { name: /Save stock/ })).toHaveCount(0);
});
