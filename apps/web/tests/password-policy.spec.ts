import { expect, test } from "@playwright/test";
import { newPasswordError } from "../src/lib/new-password";

test("new password policy counts Unicode characters and UTF-8 bytes, rejecting NUL", () => {
  expect(newPasswordError("a".repeat(12))).toBeNull();
  expect(newPasswordError("a".repeat(72))).toBeNull();
  expect(newPasswordError("a".repeat(73))).toContain("72 UTF-8 bytes");
  expect(newPasswordError("é".repeat(36))).toBeNull();
  expect(newPasswordError("é".repeat(37))).toContain("72 UTF-8 bytes");
  expect(newPasswordError("🌴".repeat(11))).toContain("12 characters");
  expect(newPasswordError("🌴".repeat(12))).toBeNull();
  expect(newPasswordError("strong-password\0")).toContain("null character");
});

test("admin setup preserves full rejected password and token, accepts exact byte boundary", async ({ page }) => {
  const writes: Record<string, unknown>[] = [];
  await page.route("**/api/v1/**", async route => {
    if (route.request().method() === "GET") return route.fulfill({ json: { csrf_token: "qa-token", user: null } });
    writes.push(route.request().postDataJSON());
    return route.fulfill({ json: { message: "Password set" } });
  });
  await page.goto("/admin/password#token=synthetic-test-token&email=qa%40example.test");
  const password = page.getByLabel("New password", { exact: true });
  const confirmation = page.getByLabel("Confirm password", { exact: true });
  await password.fill("é".repeat(37)); await confirmation.fill("é".repeat(37));
  await page.getByRole("button", { name: "Set password", exact: true }).click();
  await expect(page.locator(".error")).toContainText("72 UTF-8 bytes");
  await expect(password).toHaveValue("é".repeat(37));
  await expect(password).toBeFocused();
  expect(writes).toHaveLength(0);
  await expect(page).toHaveURL(/#token=/);
  await password.fill("é".repeat(36)); await confirmation.fill("é".repeat(36));
  await page.getByRole("button", { name: "Set password", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Your password is ready" })).toBeVisible();
  expect(writes).toHaveLength(1);
  expect(writes[0]).toEqual({ password: "é".repeat(36), password_confirmation: "é".repeat(36), token: "synthetic-test-token", email: "qa@example.test" });
  await expect(page).toHaveURL(/\/admin\/password$/);
});
