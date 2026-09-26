import { expect, test, type Page } from "@playwright/test";

async function createDraft(page: Page, email = "admin@example.test") {
  await page.goto("/admin");
  await page.getByLabel("Email address").fill(email);
  await page.getByLabel("Password", { exact: true }).fill("browser-test-password");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page.getByRole("button", { name: "Add hotel" }).click();
  await page.getByLabel("Hotel name").fill(`Wizard Test Hotel ${Date.now()}`);
  await page.getByRole("button", { name: "Create draft" }).click();
  await page.waitForURL(/\/onboarding$/);
  await expect(page.getByRole("heading", { name: "Hotel basics", exact: true })).toBeVisible();
}

async function next(page: Page, heading: string) {
  await page.getByRole("button", { name: "Save and continue", exact: true }).click();
  await expect(page.getByRole("heading", { name: heading, exact: true })).toBeVisible();
}

test("employee workflow saves, resumes, uploads photos and reviews a private hotel", async ({ page }) => {
  const errors: string[] = [];
  page.on("pageerror", e => errors.push(e.message));
  await createDraft(page, "employee@example.test");
  await page.getByLabel("Property type", { exact: true }).selectOption("hotel");
  await page.getByLabel("City or destination").fill("Ella");
  await page.getByLabel("Street address").fill("12 Temple Road");
  await page.getByLabel("Hotel contact email").fill("hello@example.test");
  await page.getByLabel("Phone number").fill("0771234567");
  await expect(page.getByText("All changes saved", { exact: true })).toBeVisible();
  await page.reload();
  await expect(page.getByLabel("Street address")).toHaveValue("12 Temple Road");
  await next(page, "Listing & photos");
  await page.getByLabel("About the hotel").fill("A quiet hotel near Ella town with garden views.");
  await page.getByLabel("Wi-Fi", { exact: true }).check();
  await page.getByLabel("Photo", { exact: true }).setInputFiles({ name: "hotel.png", mimeType: "image/png", buffer: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jOZkAAAAASUVORK5CYII=", "base64") });
  await page.getByLabel("Photo caption").fill("Deluxe room garden view");
  await page.getByRole("button", { name: "Upload photo", exact: true }).click();
  await expect(page.getByRole("img", { name: "Deluxe room garden view" })).toBeVisible();
  await next(page, "Room types");
  await page.getByRole("button", { name: "Add room type", exact: true }).click();
  await page.getByLabel("Room type name", { exact: true }).fill("Deluxe double");
  await page.getByLabel("Maximum guests", { exact: true }).fill("2");
  await expect(page.getByRole("heading", { name: "Photos of Deluxe double" })).toBeVisible();
  await page.getByLabel("Room photo", { exact: true }).setInputFiles({ name: "room.png", mimeType: "image/png", buffer: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jOZkAAAAASUVORK5CYII=", "base64") });
  await page.getByLabel("Room photo caption").fill("Deluxe double bed");
  await page.getByRole("button", { name: "Upload room photo", exact: true }).click();
  await expect(page.getByRole("img", { name: "Deluxe double bed" })).toBeVisible();
  await next(page, "Rates & availability");
  await page.getByLabel("Availability setup", { exact: true }).selectOption("manual");
  await page.locator(".meal-options section").filter({ hasText: "(RO)" }).getByRole("button", { name: "Add LKR prices" }).click();
  await page.getByLabel("Base price (LKR)").fill("25000");
  await page.getByLabel("Tax (LKR)", { exact: true }).fill("0");
  await page.getByLabel("Mandatory fees (LKR)").fill("0");
  await page.getByLabel("All mandatory charges are known and included").check();
  await next(page, "Policies");
  await page.getByLabel("Check-in from").fill("14:00");
  await page.getByLabel("Check-out by").fill("11:00");
  await page.getByLabel("Cancellation policy").fill("Free cancellation until 7 days before arrival.");
  await next(page, "Hotel staff");
  await page.getByLabel("Full name").fill("Test Manager");
  await page.getByLabel("Email address", { exact: true }).fill("manager@example.test");
  await page.getByLabel("Hotel role").selectOption("hotel_manager");
  await page.getByRole("button", { name: "Grant hotel access", exact: true }).click();
  await expect(page.getByText("Hotel access saved.", { exact: false })).toBeVisible();
  await next(page, "Review");
  await expect(page.getByRole("heading", { name: "Draft details recorded", exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Publish unavailable" })).toBeDisabled();
  await page.reload();
  await expect(page.getByRole("heading", { name: "Review", exact: true })).toBeVisible();
  await expect(page.getByText("12 Temple Road, Ella, LK", { exact: true })).toBeVisible();
  await page.screenshot({ path: "test-results/onboarding-review.png", fullPage: true });
  expect(errors).toEqual([]);
});

test("stale browser edits are preserved on screen and cannot overwrite newer server data", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await createDraft(page);
  const id = page.url().match(/hotels\/(\d+)/)![1];
  const session = await (await page.request.get("/api/v1/session")).json();
  const response = await page.request.patch(`/api/v1/hotels/${id}`, { headers: { "X-CSRF-TOKEN": session.csrf_token, Accept: "application/json" }, data: { city: "Galle", version: 0 } });
  expect(response.ok()).toBe(true);
  await page.getByLabel("City or destination").fill("Kandy");
  await expect(page.getByRole("alert").filter({ hasText: "changed in another window" })).toBeVisible();
  await expect(page.getByLabel("City or destination")).toHaveValue("Kandy");
  const persisted = await (await page.request.get(`/api/v1/hotels/${id}`)).json();
  expect(persisted.data.city).toBe("Galle");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: "test-results/onboarding-mobile.png", fullPage: true });
});


test("conflict recovery copies local input, explicitly reloads latest, and resumes saving", async ({ page, context }) => {
  await context.grantPermissions(["clipboard-read", "clipboard-write"]);
  await createDraft(page, "conflict@example.test");
  const second = await context.newPage();
  await second.goto(page.url());
  await expect(second.getByLabel("City or destination")).toBeVisible();
  await second.getByLabel("City or destination").fill("Galle");
  await expect(second.getByText("All changes saved", { exact: true })).toBeVisible();
  await page.getByLabel("City or destination").fill("Kandy");
  await expect(page.getByRole("alert").filter({ hasText: "changed in another window" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Retry save" })).toHaveCount(0);
  await page.getByLabel("Phone number").fill("0771234567");
  await page.getByText("Review and resolve unsaved changes", { exact: true }).click();
  await page.getByRole("button", { name: "Copy unsaved changes", exact: true }).click();
  expect(JSON.parse(await page.evaluate(() => navigator.clipboard.readText()))).toEqual({ city: "Kandy", phone: "0771234567" });
  await expect(page.getByLabel("City or destination")).toHaveValue("Kandy");
  await expect(page.getByText("Reloading will discard your unsaved changes in this window.", { exact: true })).toBeVisible();
  await page.screenshot({ path: "test-results/onboarding-conflict-recovery.png", fullPage: true });
  await page.route("**/onboarding", route => route.request().method() === "GET" ? route.fulfill({ status: 503, json: { message: "Unavailable" } }) : route.continue());
  await page.getByRole("button", { name: "Discard unsaved changes and reload latest", exact: true }).click();
  await expect(page.getByText("Could not load the latest draft.", { exact: false })).toBeVisible();
  await expect(page.getByLabel("City or destination")).toHaveValue("Kandy");
  await page.unroute("**/onboarding");
  await page.getByRole("button", { name: "Discard unsaved changes and reload latest", exact: true }).click();
  await expect(page.getByLabel("City or destination")).toHaveValue("Galle");
  await page.getByLabel("City or destination").fill("Ella");
  await expect(page.getByText("All changes saved", { exact: true })).toBeVisible();
  await page.reload();
  await expect(page.getByLabel("City or destination")).toHaveValue("Ella");
  await second.close();
});

test("review edits use keyboard navigation, save progress and return to the owning step", async ({ page }) => {
  await createDraft(page);
  await page.getByRole("button", { name: "7 Review", exact: true }).click();
  for (const [item, step] of [
    ["Property type", "Hotel basics"], ["Hotel photographs", "Listing & photos"],
    ["Room names, occupancy and quantities", "Room types"], ["Availability setup preference", "Rates & availability"],
    ["Cancellation policy", "Policies"], ["A hotel manager", "Hotel staff"],
  ]) {
    const edit = page.getByRole("button", { name: `${item} — edit ${step}`, exact: true });
    await edit.focus();
    await page.keyboard.press("Enter");
    await expect(page.getByRole("heading", { name: step, exact: true })).toBeFocused();
    if (item === "Property type") await page.getByLabel("City or destination").fill("Matara");
    await page.getByRole("button", { name: "7 Review", exact: true }).click();
    await expect(page.getByRole("heading", { name: "Review", exact: true })).toBeVisible();
  }
  await page.reload();
  await expect(page.getByText("Matara, LK", { exact: true })).toBeVisible();
});

test("mobile conflict disclosure keeps the first field reachable and preserves keyboard recovery", async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await createDraft(page, "recovery@example.test");
  const id = page.url().match(/hotels\/(\d+)/)![1];
  const session = await (await page.request.get("/api/v1/session")).json();
  expect((await page.request.patch(`/api/v1/hotels/${id}`, { headers: { "X-CSRF-TOKEN": session.csrf_token }, data: { city: "Galle", version: 0 } })).ok()).toBe(true);
  await page.getByLabel("City or destination").fill("Kandy");
  await expect(page.getByRole("alert").filter({ hasText: "changed in another window" })).toBeVisible();
  const rail = page.getByRole("navigation", { name: "Hotel setup steps" });
  await rail.focus();
  await expect(rail).toBeFocused();
  await page.keyboard.press("ArrowRight");
  await expect.poll(() => rail.evaluate(element => element.scrollLeft)).toBeGreaterThan(0);
  for (const button of await rail.getByRole("button").all()) {
    expect((await button.boundingBox())!.height).toBeGreaterThanOrEqual(44);
  }
  await page.evaluate(() => window.scrollTo(0, 0));
  const firstField = await page.getByLabel("Hotel name", { exact: true }).boundingBox();
  expect(firstField).not.toBeNull();
  await testInfo.attach("mobile-first-field-position", { body: JSON.stringify(firstField), contentType: "application/json" });
  expect(firstField!.y + firstField!.height).toBeLessThanOrEqual(844);
  await page.screenshot({ path: testInfo.outputPath("mobile-conflict-collapsed.png"), fullPage: true });
  const disclosure = page.getByText("Review and resolve unsaved changes", { exact: true });
  await disclosure.focus();
  await page.keyboard.press("Enter");
  await expect(page.getByLabel("Unsaved changes (JSON)")).toContainText('"city": "Kandy"');
  await expect(page.getByText("Reloading will discard your unsaved changes in this window.", { exact: true })).toBeVisible();
  await disclosure.focus();
  await page.keyboard.press("Enter");
  await expect(disclosure).toBeFocused();
  await expect(page.getByLabel("Unsaved changes (JSON)")).not.toBeVisible();
  await page.keyboard.press("Enter");
  for (const width of [320, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath(`conflict-${width}.png`), fullPage: true });
  }
  await page.getByRole("button", { name: "Discard unsaved changes and reload latest", exact: true }).click();
  await expect(page.getByLabel("City or destination")).toHaveValue("Galle");
  await page.getByLabel("City or destination").fill("Ella");
  await expect(page.getByText("All changes saved", { exact: true })).toBeVisible();
  await page.reload();
  await expect(page.getByLabel("City or destination")).toHaveValue("Ella");
});

test("review edit navigation cannot bypass a changed draft", async ({ page }) => {
  await createDraft(page, "conflict@example.test");
  await page.getByRole("button", { name: "7 Review", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Review", exact: true })).toBeVisible();
  const id = page.url().match(/hotels\/(\d+)/)![1];
  const session = await (await page.request.get("/api/v1/session")).json();
  const draft = await (await page.request.get(`/api/v1/hotels/${id}/onboarding`)).json();
  expect((await page.request.patch(`/api/v1/hotels/${id}/onboarding`, { headers: { "X-CSRF-TOKEN": session.csrf_token }, data: { version: draft.version, fields: { city: "Galle" } } })).ok()).toBe(true);
  await page.getByRole("button", { name: "Property type — edit Hotel basics", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Review", exact: true })).toBeVisible();
  await expect(page.getByRole("region", { name: "Save problem" })).toBeFocused();
  // The same stored conflict must refocus the summary on another navigation attempt.
  await page.getByRole("button", { name: "Property type — edit Hotel basics", exact: true }).focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("region", { name: "Save problem" })).toBeFocused();
  await expect(page.getByRole("button", { name: "Publish unavailable" })).toBeDisabled();
  await page.getByText("Review and resolve unsaved changes", { exact: true }).click();
  await page.getByRole("button", { name: "Discard unsaved changes and reload latest", exact: true }).click();
  await expect(page.getByText("Galle, LK", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Property type — edit Hotel basics", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Hotel basics", exact: true })).toBeFocused();
});

test("validation stays at the field during autosave and keyboard Continue focuses linked errors", async ({ page }) => {
  await createDraft(page, "validation@example.test");
  const email = page.getByLabel("Hotel contact email", { exact: true });
  await email.fill("invalid-email");
  await expect(email).toHaveAttribute("aria-invalid", "true");
  await expect(email).toBeFocused();
  await expect(page.getByRole("button", { name: "Retry save" })).toHaveCount(0);
  await page.getByRole("button", { name: "Save and continue", exact: true }).focus();
  await page.keyboard.press("Enter");
  const summary = page.getByRole("region", { name: "Check these fields" });
  await expect(summary).toBeFocused();
  await page.keyboard.press("Tab");
  await page.keyboard.press("Enter");
  await expect(email).toBeFocused();
  const errorId = await email.getAttribute("aria-describedby");
  await expect(page.locator(`[id="${errorId}"]`)).toContainText("valid email");
  await email.fill("hello@example.test");
  await next(page, "Listing & photos");
  await next(page, "Room types");
  await page.getByRole("button", { name: "Add room type", exact: true }).click();
  await page.getByLabel("Room type name", { exact: true }).fill("Validated double");
  const occupancy = page.getByLabel("Maximum guests", { exact: true });
  await occupancy.fill("0");
  await expect(occupancy).toHaveAttribute("aria-invalid", "true");
  await expect(occupancy).toBeFocused();
  await page.getByRole("button", { name: "Save and continue", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Room types", exact: true })).toBeVisible();
  await page.screenshot({ path: "test-results/onboarding-field-errors.png", fullPage: true });
  await occupancy.fill("2");
  await expect(page.getByRole("heading", { name: "Photos of Validated double" })).toBeVisible();
  await next(page, "Rates & availability");
});


test("retry recovers server failures but is not offered for forbidden writes", async ({ page }) => {
  await createDraft(page, "retry@example.test");
  await page.route("**/onboarding", route => route.request().method() === "PATCH" ? route.fulfill({ status: 503, json: { message: "Temporarily unavailable" } }) : route.continue());
  await page.getByRole("button", { name: "Save and continue", exact: true }).click();
  await expect(page.getByRole("button", { name: "Retry save" })).toBeVisible();
  await page.unroute("**/onboarding");
  await page.getByRole("button", { name: "Retry save" }).click();
  await expect(page.getByRole("heading", { name: "Listing & photos", exact: true })).toBeVisible();
  await page.route("**/onboarding", route => route.request().method() === "PATCH" ? route.fulfill({ status: 403, json: { message: "Access denied" } }) : route.continue());
  await page.getByLabel("About the hotel").fill("Local description");
  await expect(page.getByRole("region", { name: "Save problem" }).getByRole("alert")).toHaveText("Access denied");
  await expect(page.getByRole("button", { name: "Retry save" })).toHaveCount(0);
  await expect(page.getByLabel("About the hotel")).toHaveValue("Local description");
});

for (const status of [422, 409, 503]) {
  test(`sign out offers keep editing or explicit discard after a ${status} save failure`, async ({ page }) => {
    await createDraft(page, `signout-${status}@example.test`);
    await page.route("**/onboarding", route => route.request().method() === "PATCH" ? route.fulfill({ status, json: { message: "Draft could not be saved" } }) : route.continue());
    await page.getByLabel("City or destination").fill("Unsaved city");
    await expect(page.getByText("Changes not saved", { exact: true })).toBeVisible();
    await page.getByRole("button", { name: "Sign out", exact: true }).click();
    const decision = page.getByRole("dialog", { name: "Sign out without saving?" });
    await expect(decision).toBeVisible();
    await expect(decision.getByRole("button", { name: "Keep editing" })).toBeFocused();
    await page.keyboard.press("Enter");
    await expect(decision).not.toBeVisible();
    await expect(page.getByLabel("City or destination")).toHaveValue("Unsaved city");
    await page.getByRole("button", { name: "Sign out", exact: true }).click();
    await decision.getByRole("button", { name: "Discard unsaved changes and sign out" }).click();
    await expect(page.getByRole("heading", { name: "Welcome back" })).toBeVisible();
    expect((await (await page.request.get("/api/v1/session")).json()).user).toBeNull();
    expect((await page.request.get("/api/v1/hotels")).status()).toBe(401);
    await page.unroute("**/onboarding");
    await page.getByLabel("Email address").fill(`signout-${status}@example.test`);
    await page.getByLabel("Password", { exact: true }).fill("browser-test-password");
    await page.getByRole("button", { name: "Sign in", exact: true }).click();
    await expect(page.getByLabel("City or destination")).toHaveValue("");
  });
}

test("browser Back and Forward recover pending fields without silently overwriting another editor", async ({ page }) => {
  await createDraft(page, "recovery@example.test");
  const url = page.url();
  const id = url.match(/hotels\/(\d+)/)![1];
  await page.route("**/onboarding", route => route.request().method() === "PATCH" ? route.abort("failed") : route.continue());
  await page.getByLabel("City or destination").fill("Unsaved city");
  await expect(page.getByText("Changes not saved", { exact: true })).toBeVisible();
  await page.goBack();
  await expect(page.getByRole("heading", { name: "Hotels", exact: true })).toBeVisible();
  await page.goForward();
  await expect(page.getByLabel("City or destination")).toHaveValue("Unsaved city");
  await page.goBack();
  await expect(page.getByRole("heading", { name: "Hotels", exact: true })).toBeVisible();
  const session = await (await page.request.get("/api/v1/session")).json();
  expect((await page.request.patch(`/api/v1/hotels/${id}`, { headers: { "X-CSRF-TOKEN": session.csrf_token, Accept: "application/json" }, data: { city: "Other editor", version: 0 } })).ok()).toBe(true);
  await page.unroute("**/onboarding");
  await page.goto(url);
  await expect(page.getByLabel("City or destination")).toHaveValue("Unsaved city");
  await expect(page.getByRole("alert").filter({ hasText: "changed in another window" })).toBeVisible();
  expect((await (await page.request.get(`/api/v1/hotels/${id}`)).json()).data.city).toBe("Other editor");
  await page.getByText("Review and resolve unsaved changes", { exact: true }).click();
  await page.getByRole("button", { name: "Discard unsaved changes and reload latest" }).click();
  await expect(page.getByLabel("City or destination")).toHaveValue("Other editor");
  await page.getByLabel("City or destination").fill("Reviewed city");
  await expect(page.getByText("All changes saved", { exact: true })).toBeVisible();
  await page.reload();
  await expect(page.getByLabel("City or destination")).toHaveValue("Reviewed city");
});


test("room edits recover before debounce and sign out clears drafts left by browser Back", async ({ page }) => {
  await createDraft(page, "rooms-recovery@example.test");
  const url = page.url();
  await next(page, "Listing & photos");
  await next(page, "Room types");
  await page.route("**/room-types", route => route.request().method() === "POST" ? route.abort("failed") : route.continue());
  await page.getByRole("button", { name: "Add room type", exact: true }).click();
  await page.getByLabel("Room type name", { exact: true }).fill("Unsaved suite");
  await page.getByLabel("Maximum guests", { exact: true }).fill("7");
  await page.goBack();
  await expect(page.getByRole("heading", { name: "Hotels", exact: true })).toBeVisible();
  await page.goForward();
  await expect(page.getByLabel("Room type name", { exact: true })).toHaveValue("Unsaved suite");
  await expect(page.getByLabel("Maximum guests", { exact: true })).toHaveValue("7");
  await page.goBack();
  await expect(page.getByRole("heading", { name: "Hotels", exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Welcome back" })).toBeVisible();
  await page.unroute("**/room-types");
  await page.getByLabel("Email address").fill("rooms-recovery@example.test");
  await page.getByLabel("Password", { exact: true }).fill("browser-test-password");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.getByRole("button", { name: "Add hotel" })).toBeVisible();
  await page.goto(url);
  await expect(page.getByRole("heading", { name: "Room types", exact: true })).toBeVisible();
  await expect(page.getByLabel("Room type name", { exact: true })).toHaveCount(0);
});
