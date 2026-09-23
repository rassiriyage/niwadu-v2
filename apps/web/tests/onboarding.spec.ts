import { expect, test, type Page } from "@playwright/test";

async function createDraft(page: Page, email = "admin@example.test") {
  await page.goto("/admin");
  await page.getByLabel("Email address").fill(email);
  await page.getByLabel("Password", { exact: true }).fill("browser-test-password");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page.getByRole("button", { name: "Add hotel" }).click();
  await page.getByLabel("Hotel name").fill(`Wizard Test Hotel ${Date.now()}`);
  await page.getByRole("button", { name: "Create draft" }).click();
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
  await page.getByLabel("Number of rooms").fill("4");
  await page.getByRole("button", { name: "Duplicate room type", exact: true }).click();
  await expect(page.getByLabel("Room type name", { exact: true }).nth(1)).toHaveValue("Deluxe double (copy)");
  await page.getByRole("button", { name: "Remove room type", exact: true }).nth(1).click();
  await next(page, "Rates & availability");
  await page.getByLabel("Availability setup", { exact: true }).selectOption("manual");
  await page.getByLabel("Deluxe double · nightly rate (LKR)").fill("25000");
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
