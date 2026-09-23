import { expect, test } from "@playwright/test";

test("homepage stays honest while its carousel and mobile dialog work", async ({ page }) => {
  await page.goto("/");
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", "https://niwadu.com/");
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", "noindex, nofollow");
  await expect(page.getByText("View current rates", { exact: true })).toHaveCount(24);
  await expect(page.locator('script[type="application/ld+json"]')).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Search unavailable", exact: true })).toBeDisabled();
  const track = page.getByRole("list", { name: "Nuwara Eliya listings", exact: true });
  await expect(page.getByRole("button", { name: "Previous Nuwara Eliya", exact: true })).toBeDisabled();
  await page.getByRole("button", { name: "Next Nuwara Eliya", exact: true }).click();
  await expect.poll(() => track.evaluate(el => el.scrollLeft)).toBeGreaterThan(0);
  await expect(page.getByRole("button", { name: "Previous Nuwara Eliya", exact: true })).toBeEnabled();
  await page.setViewportSize({ width: 390, height: 844 });
  const trigger = page.getByRole("button", { name: "Start your search", exact: true });
  await trigger.click();
  await expect(page.getByRole("dialog", { name: "Search unavailable" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Close", exact: true })).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(page.getByRole("link", { name: "Browse hotels", exact: true })).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(page.getByRole("button", { name: "Close", exact: true })).toBeFocused();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).not.toBeVisible();
  await expect(trigger).toBeFocused();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});
