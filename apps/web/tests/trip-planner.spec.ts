import { expect, test } from "@playwright/test";

test("planner edits, reorders, reviews and restores a browser-local itinerary", async ({ page }) => {
  await page.goto("/plan");
  await expect(page.getByRole("heading", { name: "Plan your trip around the island" })).toBeVisible();
  for (const name of ["Kandy", "Ella"]) {
    await page.getByRole("searchbox", { name: "Search destinations" }).fill(name);
    await page.getByRole("button", { name: `Add ${name}`, exact: true }).click();
  }
  await expect(page.getByRole("status").filter({ hasText: "Saved in this browser" })).toBeVisible();
  await page.getByRole("button", { name: "Set order and nights" }).click();
  await page.getByRole("button", { name: "Add a night in Kandy" }).click();
  await page.getByRole("button", { name: "Move Ella earlier" }).click();
  await expect(page.locator(".planner-stops > li").first()).toContainText("Ella");
  await expect(page.getByRole("button", { name: "Move Ella later" })).toBeFocused();
  await page.getByRole("button", { name: "Review itinerary" }).click();
  await expect(page.locator(".planner-review")).toContainText("5 nights");
  await page.reload();
  await page.getByRole("button", { name: "Set order and nights" }).click();
  await expect(page.locator(".planner-stops > li").first()).toContainText("Ella");
  await expect(page.locator(".planner-stops > li").nth(1)).toContainText("3 nights");
  await page.getByRole("button", { name: "Remove Ella", exact: true }).click();
  await expect(page.locator(".planner-stops > li")).toHaveCount(1);
  await expect(page.getByRole("button", { name: "Remove Kandy", exact: true })).toBeFocused();
});

test("mobile planner keeps bounds, empty recovery and storage failure usable", async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 844 });
  await page.addInitScript(() => { Storage.prototype.setItem = () => { throw new Error("Storage blocked"); }; });
  await page.goto("/plan");
  await page.getByRole("searchbox", { name: "Search destinations" }).fill("not-a-place");
  await expect(page.getByText("No destinations match your search.")).toBeVisible();
  await page.getByRole("button", { name: "Clear search" }).click();
  await page.getByRole("searchbox", { name: "Search destinations" }).fill("Kandy");
  await page.getByRole("button", { name: "Add Kandy", exact: true }).click();
  await expect(page.locator(".planner-error")).toContainText("could not be saved");
  await page.getByRole("button", { name: "Set order and nights" }).click();
  await page.getByRole("button", { name: "Remove a night in Kandy" }).click();
  await expect(page.getByRole("button", { name: "Remove a night in Kandy" })).toBeDisabled();
  for (let i = 1; i < 14; i++) await page.getByRole("button", { name: "Add a night in Kandy" }).click();
  await expect(page.getByRole("button", { name: "Add a night in Kandy" })).toBeDisabled();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.getByRole("button", { name: "Remove Kandy", exact: true }).click();
  await expect(page.getByText("Your itinerary is empty.")).toBeVisible();
  await page.getByRole("button", { name: "Choose destinations" }).click();
  await expect(page.getByRole("searchbox", { name: "Search destinations" })).toBeVisible();
});


test("homepage planner links, damaged draft recovery and clear remain local", async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem("niwadu.trip-draft.v1", '{"version":1,"stops":[{"slug":"unknown","nights":2}]}'));
  await page.goto("/");
  await page.locator("a.promo").click();
  await expect(page).toHaveURL(/\/plan$/);
  await expect(page.locator(".planner-error")).toContainText("could not be opened");
  await page.getByRole("searchbox", { name: "Search destinations" }).fill("Ella");
  await page.getByRole("button", { name: "Add Ella", exact: true }).click();
  await expect(page.locator(".planner-error")).toHaveCount(0);
  await page.getByRole("button", { name: "Set order and nights" }).click();
  await page.getByRole("button", { name: "Review itinerary" }).click();
  const download = page.waitForEvent("download");
  await page.getByRole("button", { name: "Download itinerary" }).click();
  expect((await download).suggestedFilename()).toBe("niwadu-itinerary.txt");
  await page.getByRole("button", { name: "Start over" }).click();
  await page.getByRole("button", { name: "Keep itinerary" }).click();
  await expect(page.locator(".planner-review")).toContainText("Ella");
  await page.getByRole("button", { name: "Start over" }).click();
  await page.getByRole("button", { name: "Clear itinerary" }).click();
  expect(await page.evaluate(() => JSON.parse(localStorage.getItem("niwadu.trip-draft.v1")!).stops)).toEqual([]);
  await expect(page.getByRole("heading", { name: "Where do you want to go?" })).toBeFocused();
});
