import { expect, test } from "@playwright/test";

test("reference homepage stays local and honest", async ({ page }) => {
  await page.goto("/");
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", "https://niwadu.com/");
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", "noindex, nofollow");
  await expect(page.getByText("Rates unavailable", { exact: true })).toHaveCount(36);
  await expect(page.locator('script[type="application/ld+json"]')).toHaveCount(0);
  const hrefs = await page.locator("a[href]").evaluateAll(links => links.map(link => link.getAttribute("href")!));
  expect(hrefs.every(href => href === "/" || href.startsWith("#") || href.startsWith("tel:") || href.startsWith("mailto:"))).toBe(true);
  await expect(page.getByRole("link", { name: "Niwadu home", exact: true })).toHaveAttribute("href", "/");
  const track = page.getByRole("list", { name: "Popular stays in Nuwara Eliya listings", exact: true });
  await page.getByRole("button", { name: "Next Popular stays in Nuwara Eliya", exact: true }).click();
  await expect.poll(() => track.evaluate(el => el.scrollLeft)).toBeGreaterThan(0);
  for (const trigger of [page.getByRole("button", { name: "Search stays", exact: true }), page.locator(".listing-card").first(), page.locator(".footer-columns button").first(), page.locator(".promo").first()]) {
    const label = await trigger.getAttribute("aria-label");
    await trigger.click();
    const dialog = page.getByRole("dialog", { name: `${label} — preview only` });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole("button", { name: "Close" })).toBeFocused();
    await page.keyboard.press("Tab");
    await expect(dialog.getByRole("button", { name: "Close" })).toBeFocused();
    await page.keyboard.press("Escape");
    await expect(trigger).toBeFocused();
  }
  for (const width of [1440, 1024, 768, 390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  }
  await page.locator("summary").click();
  await page.getByRole("button", { name: "Profile", exact: true }).click();
  await expect(page.getByRole("dialog", { name: "Profile — preview only" })).toBeVisible();
  await page.getByRole("button", { name: "Close", exact: true }).click();
});
