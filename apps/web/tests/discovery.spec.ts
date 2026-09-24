import { test, expect } from "@playwright/test";
import { readQuery, searchURL } from "../src/app/hotels/discovery";

test.beforeEach(async ({ request }) => { await request.get("http://127.0.0.1:8099/__reset"); });

test("query preserves invalid intent and serializes arrays deterministically", () => {
  expect(readQuery({ sort: ["name", "editorial"] }).problems.length).toBeGreaterThan(0);
  expect(readQuery({ category: "north", adults: "2" }).problems.length).toBe(2);
  expect(readQuery({ page: "1e2" }).problems.length).toBeGreaterThan(0);
  const { query } = readQuery({ "property_types[]": ["villa", "hotel", "villa"], q: " QA " });
  expect(searchURL(query)).toBe("/hotels?q=QA&property_types%5B%5D=hotel&property_types%5B%5D=villa&sort=name");
});

test("filters apply and cancel, paging stays internal and detail returns public metadata", async ({ page }) => {
  await page.goto("/hotels?sort=name");
  await expect(page.locator(".discovery-card")).toHaveCount(24);
  await page.getByRole("button", { name: "Filters", exact: true }).click();
  await page.getByRole("checkbox", { name: "Villa", exact: true }).check();
  await page.getByRole("button", { name: "Cancel", exact: true }).click();
  await expect(page).toHaveURL(/sort=name$/);
  await page.getByRole("button", { name: "Filters", exact: true }).click();
  await expect(page.getByRole("checkbox", { name: "Villa", exact: true })).not.toBeChecked();
  await page.getByRole("checkbox", { name: "Villa", exact: true }).check();
  await page.getByRole("button", { name: "Apply filters" }).click();
  await expect(page.locator(".discovery-card")).toHaveCount(13);
  await page.getByRole("link", { name: "Clear all filters", exact: true }).click();
  await page.getByRole("link", { name: "Next page" }).click();
  await expect(page).toHaveURL(/page=2$/);
  await expect(page.locator(".discovery-card")).toHaveCount(2);
  await page.locator(".discovery-card").first().click();
  await expect(page.getByRole("heading", { name: "About this stay" })).toBeVisible();
  await expect(page.locator(".discovery-description")).toContainText("Synthetic public metadata");
  await expect(page.getByText("Booking and online payment are not available yet.", { exact: false })).toBeVisible();
});

test("unknown filters, unavailable backend, empty and beyond-last page are recoverable", async ({ page, request }) => {
  await page.goto("/hotels?sort=editorial&adults=2");
  await expect(page.getByRole("heading", { name: "Some search choices are unavailable" })).toBeVisible();
  await expect(page.locator(".discovery-card")).toHaveCount(0);
  await page.goto("/hotels?sort=name&q=nomatch");
  await expect(page.getByRole("heading", { name: "No stays match your search" })).toBeVisible();
  await page.goto("/hotels?sort=name&page=99");
  await page.getByRole("link", { name: "Return to first page" }).click();
  await expect(page.locator(".discovery-card")).toHaveCount(24);
  await request.get("http://127.0.0.1:8099/__unavailable");
  await page.reload();
  await expect(page.getByRole("heading", { name: "Stays could not be loaded" })).toBeVisible();
  await request.get("http://127.0.0.1:8099/__reset");
  await page.getByRole("link", { name: "Try again" }).click();
  await expect(page.locator(".discovery-card")).toHaveCount(24);
});

test("withdrawal is reread on history return and small screens stay within viewport", async ({ page, request }) => {
  await page.setViewportSize({ width: 320, height: 844 });
  await page.goto("/hotels?sort=name");
  await page.locator(".discovery-card").first().click();
  await expect(page.getByRole("heading", { name: "QA fixture 01", exact: true })).toBeVisible();
  await page.getByRole("link", { name: "All stays", exact: false }).first().click();
  await request.get("http://127.0.0.1:8099/__withdraw");
  await page.goBack();
  await expect(page.getByRole("heading", { name: "This stay is not available" })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});


test("valid type without current listings stays selected through Apply and Cancel", async ({ page }) => {
  await page.goto("/hotels?sort=name&property_types%5B%5D=hostel");
  await expect(page.getByRole("heading", { name: "No stays match your search" })).toBeVisible();
  await page.getByRole("button", { name: "Filters (1)", exact: true }).click();
  await expect(page.getByRole("checkbox", { name: "hostel (no current listings)", exact: true })).toBeChecked();
  await page.getByRole("button", { name: "Apply filters" }).click();
  await expect(page).toHaveURL(/property_types%5B%5D=hostel/);
  await expect(page.getByRole("heading", { name: "No stays match your search" })).toBeVisible();
  await page.getByRole("button", { name: "Filters (1)", exact: true }).click();
  await page.getByRole("checkbox", { name: "hostel (no current listings)", exact: true }).uncheck();
  await page.getByRole("button", { name: "Cancel", exact: true }).click();
  await expect(page).toHaveURL(/property_types%5B%5D=hostel/);
});
