import { expect, test, type Page } from "@playwright/test";
const png = { name: "room.png", mimeType: "image/png", buffer: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jOZkAAAAASUVORK5CYII=", "base64") };
async function draft(page: Page, email: string) {
  await page.goto("/admin"); await page.getByLabel("Email address").fill(email); await page.getByLabel("Password", { exact: true }).fill("browser-test-password"); await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page.getByRole("button", { name: "Add hotel" }).click(); await page.getByLabel("Hotel name").fill(`Rooms acceptance ${Date.now()}`); await page.getByRole("button", { name: "Create draft" }).click(); await page.waitForURL(/\/onboarding$/);
  return Number(page.url().match(/hotels\/(\d+)/)![1]);
}
async function api(page: Page, path: string, method = "GET", data?: unknown) {
  return page.evaluate(async ({ path, method, data }) => {
    const session = await (await fetch("/api/v1/session")).json();
    const result = await fetch(`/api/v1/${path}`, { method, headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": session.csrf_token }, body: data === undefined ? undefined : JSON.stringify(data) });
    return { status: result.status, body: result.status === 204 ? null : await result.json() };
  }, { path, method, data });
}
async function step(page: Page, name: string) { await page.getByRole("button", { name: new RegExp(`\\d.*${name}$`) }).click(); await expect(page.getByRole("heading", { name, exact: true })).toBeVisible(); }
async function addRoom(page: Page, name: string) {
  await page.getByRole("button", { name: "Add room type", exact: true }).click(); await page.getByLabel("Room type name", { exact: true }).fill(name); await page.getByLabel("Maximum guests", { exact: true }).fill("2");
  await expect(page.getByRole("heading", { name, exact: true })).toBeVisible(); await expect(page.getByRole("heading", { name: `Photos of ${name}`, exact: true })).toBeVisible();
}
async function photo(page: Page, caption: string) { await page.getByLabel("Room photo", { exact: true }).setInputFiles(png); await page.getByLabel("Room photo caption", { exact: true }).fill(caption); await page.getByRole("button", { name: "Upload room photo", exact: true }).click(); await expect(page.getByRole("img", { name: caption, exact: true })).toBeVisible(); }

test("employee creates rooms and separate photos with all optional meal plans and independent currency prices inside wizard", async ({ page }) => {
  const errors: string[] = []; page.on("pageerror", e => errors.push(e.message));
  const id = await draft(page, "employee@example.test");
  await step(page, "Listing & photos"); await expect(page.getByRole("heading", { name: "Property gallery" })).toBeVisible();
  await page.getByLabel("Photo", { exact: true }).setInputFiles(png); await page.getByLabel("About the hotel").fill("A real description stays autosaved while a photo is selected.");
  await expect.poll(async () => (await api(page, `hotels/${id}/onboarding`)).body.fields.description).toBe("A real description stays autosaved while a photo is selected.");
  await page.getByLabel("Photo caption", { exact: true }).fill("Hotel entrance"); await page.getByRole("button", { name: "Upload photo", exact: true }).click(); await expect(page.getByRole("img", { name: "Hotel entrance" })).toBeVisible();
  await page.getByLabel("Photo", { exact: true }).setInputFiles(png); await page.getByLabel("Photo caption", { exact: true }).fill("Hotel garden"); await page.getByRole("button", { name: "Upload photo", exact: true }).click(); await expect(page.getByRole("img", { name: "Hotel garden" })).toBeVisible();
  await page.locator("figure").filter({ has: page.getByRole("img", { name: "Hotel garden" }) }).getByRole("button", { name: "Move photo earlier" }).click(); await expect(page.locator(".photo-grid figure").first()).toContainText("Hotel garden");
  await page.reload(); await expect(page.locator(".photo-grid figure").first()).toContainText("Hotel garden");
  await step(page, "Room types"); await addRoom(page, "Garden double"); await photo(page, "Garden bed"); await photo(page, "Garden balcony");
  await page.locator("figure").filter({ has: page.getByRole("img", { name: "Garden balcony" }) }).getByRole("button", { name: "Move photo earlier" }).click();
  await expect(page.locator(".photo-grid figure").first()).toContainText("Garden balcony");
  await addRoom(page, "Family suite"); await photo(page, "Suite sitting room"); await photo(page, "Suite bedroom");
  await page.locator("figure").filter({ has: page.getByRole("img", { name: "Suite sitting room" }) }).getByRole("button", { name: "Move photo later" }).click(); await expect(page.locator(".photo-grid figure").first()).toContainText("Suite bedroom");
  await page.reload(); await expect(page.getByRole("heading", { name: "Photos of Family suite" })).toBeVisible(); await expect(page.locator(".photo-grid figure").first()).toContainText("Suite bedroom");
  await page.getByRole("button", { name: "Garden double", exact: true }).click(); await expect(page.locator(".photo-grid figure").first()).toContainText("Garden balcony");
  await page.getByRole("button", { name: "Family suite", exact: true }).click(); await expect(page.getByRole("img", { name: "Suite sitting room" })).toBeVisible(); await expect(page.getByRole("img", { name: "Garden balcony" })).toHaveCount(0);
  await step(page, "Rates & availability"); await page.getByLabel("Availability setup", { exact: true }).selectOption("manual");
  await page.getByRole("button", { name: "Garden double", exact: true }).click();
  for (const code of ["RO", "BB", "HB", "FB"]) {
    const card = page.locator(".meal-options section").filter({ hasText: `(${code})` });
    await card.getByRole("button", { name: "Add LKR prices" }).click(); await expect(page.getByLabel("Base price (LKR)")).toBeVisible();
    await page.getByLabel("Base price (LKR)").fill("12345.67"); await page.getByLabel("Tax (LKR)", { exact: true }).fill("0"); await page.getByLabel("Mandatory fees (LKR)").fill("0"); await page.getByLabel("All mandatory charges are known and included").check();
    await card.getByRole("button", { name: "Add USD prices" }).click(); await expect(page.getByLabel("Base price (USD)")).toHaveValue("");
    await page.getByLabel("Base price (USD)").fill("82.31"); await page.getByLabel("Tax (USD)", { exact: true }).fill("0"); await page.getByLabel("Mandatory fees (USD)").fill("0"); await page.getByLabel("All mandatory charges are known and included").check();
  }
  await step(page, "Policies");
  const rooms = (await api(page, `hotels/${id}/room-types`)).body.data;
  const plans = (await api(page, `hotels/${id}/room-types/${rooms[0].id}/rate-plans`)).body;
  expect(plans.data).toHaveLength(8); expect(plans.inventory_pool).toBeNull();
  expect(plans.data.every((p: { status: string }) => p.status === "draft")).toBe(true);
  await step(page, "Rates & availability");
  await page.locator(".meal-options section").filter({ hasText: "(BB)" }).getByRole("button", { name: "USD · Edit prices" }).click();
  await expect(page.getByLabel("Base price (USD)")).toHaveValue("82.31");
  for (const width of [320, 768, 1024, 1440]) { await page.setViewportSize({ width, height: 900 }); expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBe(true); await page.screenshot({ path: `test-results/wizard-rates-${width}.png`, fullPage: true }); }
  expect(errors).toEqual([]);
});

test("new room inputs survive reload before debounce and resume without duplicates", async ({ page }) => {
  const id = await draft(page, "rooms-recovery@example.test"); await step(page, "Room types");
  await page.getByRole("button", { name: "Add room type", exact: true }).click(); await page.getByLabel("Room type name", { exact: true }).fill("Recovered double");
  page.once("dialog", dialog => dialog.accept()); await page.reload(); await expect(page.getByLabel("Room type name", { exact: true })).toHaveValue("Recovered double");
  await page.getByLabel("Maximum guests", { exact: true }).fill("2"); await expect(page.getByRole("heading", { name: "Photos of Recovered double" })).toBeVisible();
  expect((await api(page, `hotels/${id}/room-types`)).body.data).toHaveLength(1);
});

test("uncertain room photo upload requires gallery reconciliation and preserves isolation", async ({ page }) => {
  const id = await draft(page, "recovery@example.test"); await step(page, "Room types"); await addRoom(page, "Photo recovery room");
  const room = (await api(page, `hotels/${id}/room-types`)).body.data[0]; let lost = false;
  await page.route(`**/api/v1/hotels/${id}/room-types/${room.id}/photos`, async route => { if (route.request().method() === "POST" && !lost) { lost = true; await route.fetch(); await route.abort(); } else await route.continue(); });
  await page.getByLabel("Room photo", { exact: true }).setInputFiles(png); await page.getByLabel("Room photo caption").fill("Recovered photo"); await page.getByRole("button", { name: "Upload room photo", exact: true }).click();
  await expect(page.getByText("Nothing will be retried automatically.", { exact: false })).toBeVisible(); await expect(page.getByRole("button", { name: "Upload room photo", exact: true })).toBeDisabled();
  page.once("dialog", d => d.accept()); await page.getByRole("button", { name: "Reload gallery and discard selected upload" }).click(); await expect(page.getByRole("img", { name: "Recovered photo" })).toBeVisible();
  const photos = (await api(page, `hotels/${id}/room-types/${room.id}/photos`)).body.data.photos; expect(photos).toHaveLength(1);
  expect((await api(page, `hotels/${id}/photos`)).body.data).toEqual([]);
  expect((await api(page, `hotels/${id}/photos/${photos[0].id}`)).status).toBe(404);
});

test("legacy conversion is explicit and preserves distinct catalog identity on reload", async ({ page }) => {
  const id = await draft(page, "validation@example.test"); const state = (await api(page, `hotels/${id}/onboarding`)).body;
  expect((await api(page, `hotels/${id}/onboarding`, "PATCH", { version: state.version, fields: { rooms: [{ name: "Legacy double", occupancy: 2, quantity: 3, rate: 9000 }] } })).status).toBe(200);
  await page.reload(); await step(page, "Room types"); await expect(page.getByLabel("Saved room 1 name")).toHaveValue("Legacy double");
  await page.getByRole("button", { name: "Use these saved room drafts" }).click(); await page.getByRole("button", { name: "Legacy double", exact: true }).click(); await expect(page.getByLabel("Room type name", { exact: true })).toHaveValue("Legacy double");
  await page.reload(); await expect(page.getByRole("button", { name: "Use these saved room drafts" })).toHaveCount(0);
  const rooms = (await api(page, `hotels/${id}/room-types`)).body.data; expect(rooms).toHaveLength(1);
  expect((await api(page, `hotels/${id}/room-types/${rooms[0].id}/rate-plans`)).body.data).toEqual([]);
});

test("stale room edits preserve inputs and require explicit reload", async ({ page }) => {
  const id = await draft(page, "conflict@example.test"); await step(page, "Room types"); await addRoom(page, "Original room");
  const room = (await api(page, `hotels/${id}/room-types`)).body.data[0];
  expect((await api(page, `hotels/${id}/room-types/${room.id}`, "PUT", { version: room.version, name: "Other editor", max_occupancy: 3, status: "draft" })).status).toBe(200);
  await page.getByLabel("Room type name", { exact: true }).fill("My unsaved room"); await expect(page.getByLabel("Unsaved inputs")).toContainText("My unsaved room");
  await page.getByRole("button", { name: /4.*Rates & availability/ }).click(); await expect(page.getByRole("heading", { name: "Room types", exact: true })).toBeVisible();
  page.once("dialog", d => d.accept()); await page.getByRole("button", { name: "Discard inputs and reload latest" }).click(); await expect(page.getByLabel("Room type name", { exact: true })).toHaveValue("Other editor");
});

test("uncertain room creation replays the original key without creating a duplicate", async ({ page }) => {
  const id = await draft(page, "retry@example.test"); await step(page, "Room types");
  let lost = false;
  await page.route(`**/api/v1/hotels/${id}/room-types`, async route => { if (route.request().method() === "POST" && !lost) { lost = true; await route.fetch(); await route.abort(); } else await route.continue(); });
  await page.getByRole("button", { name: "Add room type", exact: true }).click(); await page.getByLabel("Room type name", { exact: true }).fill("Retry room"); await page.getByLabel("Maximum guests", { exact: true }).fill("2");
  await expect(page.getByRole("button", { name: "Retry original room creation" })).toBeVisible(); await page.getByRole("button", { name: "Retry original room creation" }).click(); await expect(page.getByRole("heading", { name: "Photos of Retry room" })).toBeVisible();
  expect((await api(page, `hotels/${id}/room-types`)).body.data).toHaveLength(1);
});

test("unknown charges remain null and session expiry clears the private editor", async ({ page }) => {
  const id = await draft(page, "signout-422@example.test"); await step(page, "Room types"); await addRoom(page, "Incomplete offer room");
  await step(page, "Rates & availability"); await page.getByLabel("Availability setup").selectOption("manual");
  await page.locator(".meal-options section").filter({ hasText: "(HB)" }).getByRole("button", { name: "Add USD prices" }).click();
  await page.getByLabel("Base price (USD)").fill("31.29"); await step(page, "Policies");
  const room = (await api(page, `hotels/${id}/room-types`)).body.data[0]; const plan = (await api(page, `hotels/${id}/room-types/${room.id}/rate-plans`)).body.data[0];
  const from = new Date().toISOString().slice(0, 10), end = new Date(); end.setUTCDate(end.getUTCDate() + 1);
  const night = (await api(page, `hotels/${id}/room-types/${room.id}/rate-plans/${plan.id}/nights?from=${from}&to=${end.toISOString().slice(0, 10)}`)).body.data[0];
  expect(night.base_minor).toBe(3129); expect(night.tax_minor).toBeNull(); expect(night.fee_minor).toBeNull(); expect(Boolean(night.mandatory_charges_complete)).toBe(false);
  await step(page, "Room types");
  // The step heading renders before the room fetch completes. Expire an already loaded editor.
  await expect(page.getByLabel("Room type name", { exact: true })).toBeVisible();
  await api(page, "logout", "POST"); await page.getByLabel("Room type name", { exact: true }).fill("Must not save");
  await expect(page.getByText("Your session ended or changed. Sign in again to resume setup.")).toBeVisible(); await expect(page.getByLabel("Room type name", { exact: true })).toHaveCount(0);
  expect(await page.evaluate(() => Object.keys(sessionStorage).filter(k => k.startsWith("niwadu-onboarding:")))).toEqual([]);
});

test("uncertain property reorder reconciles persisted order without advancing hotel draft version", async ({ page }) => {
  const id = await draft(page, "signout-409@example.test"); await step(page, "Listing & photos");
  for (const caption of ["Property front", "Property pool"]) { await page.getByLabel("Photo", { exact: true }).setInputFiles(png); await page.getByLabel("Photo caption", { exact: true }).fill(caption); await page.getByRole("button", { name: "Upload photo", exact: true }).click(); await expect(page.getByRole("img", { name: caption })).toBeVisible(); }
  const before = (await api(page, `hotels/${id}/onboarding`)).body.version; let lost = false;
  await page.route(`**/api/v1/hotels/${id}/photos`, async route => { if (route.request().method() === "PUT" && !lost) { lost = true; await route.fetch(); await route.abort(); } else await route.continue(); });
  await page.locator("figure").filter({ has: page.getByRole("img", { name: "Property pool" }) }).getByRole("button", { name: "Move photo earlier" }).click();
  await expect(page.getByText("nothing will be retried automatically.", { exact: false })).toBeVisible();
  await page.getByRole("button", { name: "Reload property gallery and discard selected upload" }).click(); await expect(page.locator(".photo-grid figure").first()).toContainText("Property pool");
  expect((await api(page, `hotels/${id}/onboarding`)).body.version).toBe(before);
  await page.reload(); await expect(page.locator(".photo-grid figure").first()).toContainText("Property pool");
});
