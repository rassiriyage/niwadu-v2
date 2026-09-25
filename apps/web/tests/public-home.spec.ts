import { expect, test } from "@playwright/test";
import content from "../src/app/homepage-content.json";

test("reference homepage stays local and honest", async ({ page }) => {
  await page.goto("/");
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", "https://niwadu.com/");
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", "noindex, nofollow");
  await expect(page.getByText("Rates unavailable", { exact: true })).toHaveCount(36);
  await expect(page.locator('script[type="application/ld+json"]')).toHaveCount(0);
  const hrefs = await page.locator("a[href]").evaluateAll(links => links.map(link => link.getAttribute("href")!));
  const categoryRoutes = new Map([
    ["Beach", "/hotels?themes%5B%5D=beach&sort=name"],
    ["Hill country", "/hotels?themes%5B%5D=hills&sort=name"],
    ["Wildlife", "/hotels?themes%5B%5D=wild&sort=name"],
    ["Cultural triangle", "/hotels?themes%5B%5D=culture&sort=name"],
    ["Adventure", "/hotels?themes%5B%5D=adventure&sort=name"],
    ["City breaks", "/hotels?themes%5B%5D=city&sort=name"],
    ["North & East", "/hotels?region=north-east&sort=name"],
  ]);
  const allowed = new Set(["/", "/plan", "/cover", "/account", "/account?mode=register", "/hotels?sort=name", ...categoryRoutes.values()]);
  expect(hrefs.filter(href => !allowed.has(href) && !href.startsWith("#") && !href.startsWith("tel:") && !href.startsWith("mailto:")), "Unexpected homepage navigation targets").toEqual([]);
  const categories = page.getByRole("navigation", { name: "Stay categories" });
  for (const [label, href] of categoryRoutes) await expect(categories.getByRole("link", { name: label, exact: true })).toHaveAttribute("href", href);
  await expect(page.getByRole("link", { name: "Niwadu home", exact: true })).toHaveAttribute("href", "/");
  const track = page.getByRole("list", { name: "Popular stays in Nuwara Eliya listings", exact: true });
  await page.getByRole("button", { name: "Next Popular stays in Nuwara Eliya", exact: true }).click();
  await expect.poll(() => track.evaluate(el => el.scrollLeft)).toBeGreaterThan(0);
  for (const trigger of [page.locator(".listing-card").first(), page.locator(".footer-columns button").first()]) {
    await trigger.click();
    const dialog = page.getByRole("dialog", { name: /preview only$/ });
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
  await expect(page.getByRole("link", { name: "Profile", exact: true })).toHaveAttribute("href", "/account");
});

test("public photos use responsive optimized delivery with one priority image", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/");
  await expect(page.locator('img[loading="eager"]')).toHaveCount(1);
  await expect(page.locator('img[fetchpriority="high"]')).toHaveCount(1);
  await expect(page.locator(".photo-slot")).toHaveCount(52);
  expect(await page.locator(".photo-slot > img").count()).toBeLessThan(52);
  const lastPhoto = page.locator(".card-track").last().locator(".photo-slot").first();
  await lastPhoto.scrollIntoViewIfNeeded();
  await expect.poll(() => lastPhoto.locator("img").evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
  const photo = page.locator(".card-image img").first();
  await expect.poll(() => photo.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
  const source = await photo.evaluate((img: HTMLImageElement) => img.currentSrc);
  expect(new URL(source).pathname).toBe("/_next/image");
  expect(new URL(source).searchParams.get("q")).toBe("90");
  const box = await photo.boundingBox();
  const dpr = await page.evaluate(() => window.devicePixelRatio);
  const first = content[0].cards[0];
  const requiredWidth = Math.max(box!.width, box!.height * first.imageWidth / first.imageHeight) * dpr;
  expect(Number(new URL(source).searchParams.get("w"))).toBeGreaterThanOrEqual(Math.floor(requiredWidth));
  const response = await page.request.get(source, { headers: { Accept: "image/webp" } });
  expect(response.status()).toBe(200);
  expect(response.headers()["content-type"]).toBe("image/webp");
  expect(response.headers()["cache-control"]).toContain("max-age=86400");
  expect((await response.body()).length).toBeGreaterThan(0);
});
