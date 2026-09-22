import { readFileSync } from "node:fs";
import path from "node:path";
import { expect, test } from "@playwright/test";

async function signIn(page: import("@playwright/test").Page, email: string) {
  await page.goto("/admin");
  await page.getByLabel("Email address").fill(email);
  await page.getByLabel("Password", { exact: true }).fill("browser-test-password");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Hotels", exact: true })).toBeVisible();
}

test("administrator creates a hotel draft and its profile persists after reload", async ({ page }) => {
  const errors: string[] = [];
  page.on("pageerror", error => errors.push(error.message));
  await signIn(page, "admin@example.test");
  await page.getByRole("button", { name: "Add hotel" }).click();
  const name = `New Test Hotel ${Date.now()}`;
  await page.getByLabel("Hotel name").fill(name);
  await page.getByLabel("City or destination").fill("Ella");
  await page.getByRole("button", { name: "Create draft" }).click();
  await expect(page.getByRole("heading", { name, exact: true })).toBeVisible();
  await expect(page.getByText("Private draft · This hotel is not published and cannot receive bookings.")).toBeVisible();
  await page.getByLabel("Phone number").fill("0771234567");
  await page.getByRole("button", { name: "Save details" }).click();
  await expect(page.getByRole("status")).toHaveText("Hotel details saved.");
  await page.reload();
  await expect(page.getByLabel("Phone number")).toHaveValue("0771234567");
  await page.screenshot({ path: "test-results/admin-hotel.png", fullPage: true });
  expect(errors).toEqual([]);
});

test("hotel manager sees only their hotel and cannot manage staff", async ({ page }) => {
  await signIn(page, "manager@example.test");
  await expect(page.getByRole("link", { name: /Harbour Test Hotel/ })).toBeVisible();
  await expect(page.getByRole("link", { name: /Hill Test Hotel/ })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Add hotel" })).toHaveCount(0);
  await page.getByRole("link", { name: /Harbour Test Hotel/ }).click();
  await expect(page.getByRole("heading", { name: "Hotel staff", exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Grant hotel access" })).toHaveCount(0);
  await page.getByRole("button", { name: "Sign out" }).click();
  await expect(page.getByRole("heading", { name: "Welcome back" })).toBeVisible();
  await page.reload();
  await expect(page.getByRole("heading", { name: "Welcome back" })).toBeVisible();
});

test("administrator can assign and revoke access through the staff form", async ({ page }) => {
  await signIn(page, "admin@example.test");
  await page.getByRole("link", { name: /Hill Test Hotel/ }).click();
  await page.getByLabel("Full name").fill("Test Manager");
  await page.getByLabel("Email address").fill("manager@example.test");
  await page.getByLabel("Hotel role").selectOption("viewer");
  await page.getByRole("button", { name: "Grant hotel access" }).click();
  await expect(page.getByText("Hotel access saved.", { exact: false })).toBeVisible();
  await page.getByRole("button", { name: "Remove access for Test Manager" }).click();
  await page.getByRole("button", { name: "Confirm removal" }).click();
  await expect(page.getByText("Access removed for Test Manager.")).toBeVisible();
  await expect(page.getByText("No staff assigned yet.")).toBeVisible();
});

test("mobile staff sign-in and hotel list fit the viewport", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await signIn(page, "manager@example.test");
  await expect(page.getByRole("link", { name: /Harbour Test Hotel/ })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: "test-results/mobile-hotels.png", fullPage: true });
});


test("an invited member sets a password and signs in with hotel-scoped access", async ({ page }) => {
  const invitation = JSON.parse(readFileSync(path.resolve(__dirname, "../../api/storage/framework/testing/browser-invitation.json"), "utf8"));
  await page.goto(`/admin/password#${new URLSearchParams(invitation)}`);
  await page.getByLabel("New password", { exact: true }).fill("invited-browser-password");
  await page.getByLabel("Confirm password").fill("invited-browser-password");
  await page.getByRole("button", { name: "Set password", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Your password is ready" })).toBeVisible();
  expect(new URL(page.url()).hash).toBe("");
  await page.getByRole("link", { name: "Sign in", exact: true }).click();
  await page.getByLabel("Email address").fill(invitation.email);
  await page.getByLabel("Password", { exact: true }).fill("invited-browser-password");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page.getByRole("link", { name: /Harbour Test Hotel/ }).click();
  await expect(page.getByLabel("Hotel name")).toBeDisabled();
  await expect(page.getByRole("heading", { name: "Hotel staff", exact: true })).toHaveCount(0);
});
