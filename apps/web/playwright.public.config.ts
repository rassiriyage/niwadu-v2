import { defineConfig, devices } from "@playwright/test";

// Start a production web build separately; these public tests require no API or seeded database.
export default defineConfig({
  testDir: "./tests",
  testMatch: ["public-home.spec.ts", "carousel-drag.spec.ts", "trip-planner.spec.ts", "account-coverage.spec.ts", "password-policy.spec.ts", "saved-itineraries.spec.ts"],
  workers: 1,
  use: { ...devices["Desktop Chrome"], baseURL: process.env.PUBLIC_TEST_BASE_URL || "http://127.0.0.1:3208", trace: "retain-on-failure" },
});
