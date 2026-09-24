import { test, expect } from "@playwright/test";
import { readQuery, searchURL } from "../src/app/hotels/discovery";

test.beforeEach(async ({ request }) => { await request.get("http://127.0.0.1:8099/__reset"); });

test("query preserves invalid intent and serializes arrays deterministically", () => {
  expect(readQuery({ sort: ["name", "editorial"] }).problems.length).toBeGreaterThan(0);
  expect(readQuery({ category: "north", adults: "2" }).problems.length).toBe(1);
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


test("dated rates preserve URL choices, show complete total and recover from empty or invalid searches", async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 844 });
  await page.goto("/hotels/qa-fixture-1");
  await page.getByLabel("Arrival", { exact: true }).fill("2030-01-10");
  await page.getByLabel("Departure", { exact: true }).fill("2030-01-12");
  await page.getByRole("button", { name: "Check rates", exact: true }).click();
  await expect(page).toHaveURL(/arrival=2030-01-10&departure=2030-01-12&adults=2/);
  await expect(page.getByRole("heading", { name: "Garden room · Flexible" })).toBeVisible();
  await expect(page.locator(".stay-rate-list")).toContainText("12,505.00");
  await expect(page.locator(".stay-rates")).toContainText("Nothing is reserved");
  await expect(page.locator(".stay-rate-list")).toContainText("Synthetic cancellation policy.");
  await page.reload();
  await expect(page.getByLabel("Arrival", { exact: true })).toHaveValue("2030-01-10");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.getByLabel("Adults", { exact: true }).fill("3");
  await page.getByRole("button", { name: "Check rates", exact: true }).click();
  await expect(page.locator(".stay-rates")).toContainText("No room rates are available");
  await page.goto("/hotels/qa-fixture-1?arrival=2030-01-12&departure=2030-01-10&adults=2");
  await expect(page.locator(".stay-rates").getByRole("alert")).toContainText("Check your dates and guests");
  await page.goto("/hotels/qa-fixture-1?arrival=2030-01-10&arrival=2030-01-11&departure=2030-01-12&adults=2");
  await expect(page.locator(".stay-rates").getByRole("alert")).toContainText("repeated search fields");
  await expect(page.locator(".stay-rate-list")).toHaveCount(0);
  await page.getByRole("link", { name: "Clear dates and guests" }).click();
  await expect(page).toHaveURL(/qa-fixture-1$/);
});


test("room rate service failures retain choices and permit retry without claiming availability", async ({ page, request }) => {
  await request.get("http://127.0.0.1:8099/__rates-unavailable");
  await page.goto("/hotels/qa-fixture-1?arrival=2030-01-10&departure=2030-01-12&adults=2");
  await expect(page.locator(".stay-rates").getByRole("alert")).toContainText("Room rates could not be loaded");
  await expect(page.getByLabel("Arrival", { exact: true })).toHaveValue("2030-01-10");
  await expect(page.locator(".stay-rate-list")).toHaveCount(0);
  await request.get("http://127.0.0.1:8099/__reset");
  await page.getByRole("button", { name: "Check rates", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Garden room · Flexible" })).toBeVisible();
});

test("expanded choices preserve combined filters across search and sorting", async ({ page, request }) => {
  await request.get("http://127.0.0.1:8099/__expanded");
  await page.goto("/");
  await page.getByRole("navigation", { name: "Stay categories" }).getByRole("link", { name: "Beach", exact: true }).click();
  await expect(page).toHaveURL(/themes%5B%5D=beach/);
  await expect(page.locator(".discovery-count")).toHaveText("13 stays");
  await page.goto("/hotels?sort=name&page=2");
  await page.getByRole("button", { name: "Filters", exact: true }).click();
  await page.getByLabel("Destination", { exact: true }).selectOption("galle");
  for (const name of ["Beach", "Wi-Fi", "Pool"]) await page.getByRole("checkbox", { name, exact: true }).check();
  await page.getByRole("button", { name: "Apply filters" }).click();
  await expect(page).not.toHaveURL(/page=2/);
  await expect(page.locator(".discovery-count")).toHaveText("7 stays");
  await page.getByRole("combobox", { name: "Sort", exact: true }).selectOption("editorial");
  await page.getByRole("button", { name: "Apply sort", exact: true }).click();
  await expect(page.locator(".discovery-card").first()).toContainText("QA fixture 13");
  await page.getByRole("button", { name: "Filters (4)", exact: true }).click();
  await page.getByRole("checkbox", { name: "Beach", exact: true }).uncheck();
  await page.getByRole("button", { name: "Cancel", exact: true }).click();
  await page.getByLabel("Search stays", { exact: true }).fill("QA fixture 01");
  await page.getByRole("button", { name: "Search", exact: true }).click();
  await expect(page.locator(".discovery-count")).toHaveText("1 stay");
  await expect(page).toHaveURL(/destination=galle.*themes%5B%5D=beach.*sort=editorial/);
  await page.goto("/hotels?dest=galle&category=beach&sort=popular");
  await expect(page.locator(".discovery-count")).toHaveText("7 stays");
  await page.goto("/hotels?category=north");
  await expect(page.getByRole("heading", { name: "No stays match your search" })).toBeVisible();
  await page.goto("/hotels?destination=unknown");
  await expect(page.getByRole("heading", { name: "Some search choices are unavailable" })).toBeVisible();
});

test("editorial intent does not fall back when no reviewed order exists", async ({ page }) => {
  await page.goto("/hotels?sort=popular");
  await expect(page.getByRole("heading", { name: "Some search choices are unavailable" })).toBeVisible();
  await page.getByRole("button", { name: "Apply sort", exact: true }).click();
  await expect(page).toHaveURL(/sort=editorial/);
  await expect(page.locator(".discovery-card")).toHaveCount(0);
  await page.getByRole("combobox", { name: "Sort", exact: true }).selectOption("name");
  await page.getByRole("button", { name: "Apply sort", exact: true }).click();
  await expect(page.locator(".discovery-card")).toHaveCount(24);
});
