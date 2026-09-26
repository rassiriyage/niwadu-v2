import { expect, test, type Page } from "@playwright/test";

async function openStaff(page: Page) {
  await page.goto("/admin");
  await page.getByLabel("Email address").fill("admin@example.test");
  await page.getByLabel("Password", { exact: true }).fill("browser-test-password");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page.getByRole("link", { name: /Hill Test Hotel/ }).click();
  await expect(page.getByRole("heading", { name: "Hotel staff", exact: true })).toBeVisible();
  await expect(page.getByText("Loading staff…")).toHaveCount(0);
}

async function grant(page: Page, email: string) {
  await page.getByLabel("Full name").fill("Feedback Test Member");
  await page.getByLabel("Email address").fill(email);
  await page.getByLabel("Hotel role").selectOption("viewer");
  await page.getByRole("button", { name: "Grant hotel access" }).click();
}

test("existing staff access is saved without claiming a password link was sent", async ({ page }) => {
  await openStaff(page);
  await grant(page, "manager@example.test");
  await expect(page.getByRole("status")).toHaveText('Hotel access saved. No password-setup link was sent. Use "Send password link" if one is needed.');
  await expect(page.getByRole("button", { name: "Send password link to Test Manager", exact: true })).toBeVisible();
});

test("new staff access reports the password link only when the API confirms it", async ({ page }) => {
  await openStaff(page);
  const email = `staff-feedback-${Date.now()}@example.test`;
  await grant(page, email);
  await expect(page.getByRole("status")).toHaveText(`Hotel access saved. Password-setup link sent to ${email}.`);
});

test("a failed roster refresh preserves the confirmed grant and offers reload instead of another grant", async ({ page }) => {
  await openStaff(page);
  let grants = 0;
  await page.route("**/api/v1/hotels/*/staff", async route => {
    if (route.request().method() === "POST") {
      grants++;
      await route.continue();
    } else {
      await route.fulfill({ status: 503, json: { message: "Roster unavailable" } });
    }
  });
  await grant(page, "manager@example.test");
  await expect(page.getByRole("status")).toContainText("Hotel access saved. No password-setup link was sent.");
  await expect(page.locator("section").filter({ has: page.getByRole("heading", { name: "Hotel staff", exact: true }) }).getByRole("alert")).toHaveText("Access was saved, but the staff list could not be refreshed. Reload this page to see the latest list.");
  expect(grants).toBe(1);
  await expect(page.getByLabel("Email address")).toHaveValue("");
  await page.unroute("**/api/v1/hotels/*/staff");
  await page.reload();
  await expect(page.getByRole("button", { name: "Send password link to Test Manager", exact: true })).toBeVisible();
});
