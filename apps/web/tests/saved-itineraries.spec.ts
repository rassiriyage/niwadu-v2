import { test, expect, type Page } from "@playwright/test";
const stop = { slug: "galle", nights: 2 };
async function fixture(page: Page) {
  const state = { user: 1, rows: [] as { id: number; name: string; stops: typeof stop[]; version: number }[], writes: [] as { method: string; body: Record<string, unknown> }[], loseCreate: false, failSession: false };
  const creates = new Map<string, { id: number; name: string; stops: typeof stop[]; version: number }>();
  await page.route("**/api/v1/**", async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname.endsWith("/session") && state.failSession) return route.abort();
    if (url.pathname.endsWith("/session")) return route.fulfill({ json: { csrf_token: "test-token", user: state.user ? { id: state.user, name: `Traveller ${state.user}`, email: "test@example.test" } : null } });
    if (!url.pathname.includes("/me/itineraries")) return route.fulfill({ status: 404, json: {} });
    const id = Number(url.pathname.split("/").at(-1));
    const row = state.rows.find(item => item.id === id);
    if (request.method() === "GET") return Number.isNaN(id) ? route.fulfill({ json: { data: state.rows, meta: { current_page: 1, last_page: 1, total: state.rows.length } } }) : route.fulfill({ status: row ? 200 : 404, json: { data: row } });
    const body = request.postDataJSON(); state.writes.push({ method: request.method(), body });
    expect(request.headers()["x-csrf-token"]).toBe("test-token");
    if (request.method() === "POST") {
      const key = request.headers()["idempotency-key"];
      expect(key).toMatch(/^[0-9a-f-]{36}$/);
      if (creates.has(key)) return route.fulfill({ status: 201, json: { data: creates.get(key) } });
      const created = { id: state.rows.length + 1, name: body.name, stops: body.stops, version: 1 }; state.rows.push(created); creates.set(key, structuredClone(created));
      if (state.loseCreate) { state.loseCreate = false; return route.abort(); }
      return route.fulfill({ status: 201, json: { data: created } });
    }
    if (!row) return route.fulfill({ status: 404, json: { message: "Not found" } });
    if (body.version !== row.version) return route.fulfill({ status: 409, json: { message: "This itinerary changed. Reload it before saving again." } });
    if (request.method() === "DELETE") { state.rows = state.rows.filter(item => item.id !== id); return route.fulfill({ status: 204 }); }
    Object.assign(row, { ...body, version: row.version + 1 }); return route.fulfill({ json: { data: row } });
  });
  return state;
}

test("account trips explicitly copy browser draft, save, edit and delete without changing local draft", async ({ page }) => {
  const state = await fixture(page);
  await page.addInitScript(value => localStorage.setItem("niwadu.trip-draft.v1", JSON.stringify(value)), { version: 1, stops: [stop] });
  await page.goto("/plan/saved");
  await expect(page.getByText("0 saved trips")).toBeVisible();
  expect(state.writes).toHaveLength(0);
  await page.getByRole("button", { name: "Copy browser draft", exact: true }).click();
  await page.getByLabel("Trip name", { exact: true }).fill("Island journey");
  await page.getByRole("button", { name: "Save itinerary", exact: true }).click();
  await expect(page.getByText("Itinerary saved to your account.", { exact: true })).toBeVisible();
  expect(state.writes[0].body).toEqual({ name: "Island journey", stops: [stop] });
  await page.getByLabel("Trip name", { exact: true }).fill("Revised journey");
  await page.getByRole("button", { name: "Save itinerary", exact: true }).click();
  await expect(page.getByText("Itinerary saved to your account.", { exact: true })).toBeVisible();
  expect(state.writes[1].body.version).toBe(1);
  expect(await page.evaluate(() => JSON.parse(localStorage.getItem("niwadu.trip-draft.v1")!))).toEqual({ version: 1, stops: [stop] });
  page.once("dialog", dialog => dialog.accept());
  await page.getByRole("button", { name: "Delete itinerary", exact: true }).click();
  await expect(page.getByText("Itinerary deleted.", { exact: true })).toBeVisible();
  expect(state.writes[2]).toEqual({ method: "DELETE", body: { version: 2 } });
});

test("ambiguous create replays its original key and reads current state without duplicates", async ({ page }) => {
  const state = await fixture(page); state.loseCreate = true;
  await page.goto("/plan/saved");
  await page.getByRole("button", { name: "New itinerary", exact: true }).click();
  await page.getByRole("button", { name: "Save itinerary", exact: true }).click();
  await expect(page.getByRole("region", { name: "Save recovery" })).toContainText("this will not create a duplicate");
  await expect(page.getByRole("button", { name: "Save itinerary", exact: true })).toBeDisabled();
  await expect(page.getByLabel("Trip name", { exact: true })).toHaveValue("Untitled trip");
  await page.getByRole("button", { name: "Refresh saved trips", exact: true }).click();
  await expect(page.getByText("1 saved trips")).toBeVisible();
  expect(state.writes).toHaveLength(1);
  await expect(page.getByRole("button", { name: "Save itinerary", exact: true })).toBeDisabled();
  state.rows[0].name = "Edited on another device"; state.rows[0].version = 2;
  await page.getByRole("button", { name: "Retry original save", exact: true }).click();
  await expect(page.getByLabel("Trip name", { exact: true })).toHaveValue("Edited on another device");
  expect(state.rows).toHaveLength(1);
  expect(state.writes[1].body).toEqual(state.writes[0].body);
});

test("conflicts keep edits until explicit comparison and reviewed save", async ({ page }) => {
  const state = await fixture(page); state.rows = [{ id: 1, name: "Saved trip", stops: [stop], version: 1 }];
  await page.goto("/plan/saved");
  await page.getByRole("button", { name: "Saved trip", exact: true }).click();
  await page.getByLabel("Trip name", { exact: true }).fill("My edit");
  state.rows[0].version = 2; state.rows[0].name = "Other device edit";
  await page.getByRole("button", { name: "Save itinerary", exact: true }).click();
  await expect(page.getByLabel("Trip name", { exact: true })).toHaveValue("My edit");
  await page.getByRole("button", { name: "Compare saved itinerary", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Saved version: Other device edit" })).toBeVisible();
  await page.getByRole("button", { name: "Keep my edits after review", exact: true }).click();
  expect(state.writes).toHaveLength(1);
  await page.getByRole("button", { name: "Save itinerary", exact: true }).click();
  await expect(page.getByText("Itinerary saved to your account.", { exact: true })).toBeVisible();
  expect(state.writes[1].body.version).toBe(2);
});

test("account change clears private editor before writing and keeps browser draft separate", async ({ page }) => {
  const state = await fixture(page); state.rows = [{ id: 1, name: "Private trip", stops: [stop], version: 1 }];
  await page.setViewportSize({ width: 320, height: 844 });
  await page.goto("/plan/saved");
  await page.getByRole("button", { name: "Private trip", exact: true }).click();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await expect(page.getByLabel("Trip name", { exact: true })).toHaveValue("Private trip");
  state.user = 2;
  await page.getByRole("button", { name: "Save itinerary", exact: true }).click();
  await expect(page.getByLabel("Trip name", { exact: true })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Private trip", exact: true })).toHaveCount(0);
  expect(state.writes).toHaveLength(0);
  expect(await page.evaluate(() => localStorage.getItem("niwadu.trip-draft.v1"))).toBeNull();
});


test("replay after deletion never recreates the itinerary", async ({ page }) => {
  const state = await fixture(page); state.loseCreate = true;
  await page.goto("/plan/saved");
  await page.getByRole("button", { name: "New itinerary", exact: true }).click();
  await page.getByRole("button", { name: "Save itinerary", exact: true }).click();
  await expect(page.getByRole("button", { name: "Retry original save", exact: true })).toBeVisible();
  state.rows = [];
  await page.getByRole("button", { name: "Retry original save", exact: true }).click();
  await expect(page.getByText("The original save completed, but that itinerary is no longer available. No new copy was created.", { exact: true })).toBeVisible();
  expect(state.rows).toHaveLength(0);
});


test("a transient session check failure preserves unsaved private edits", async ({ page }) => {
  const state = await fixture(page); state.rows = [{ id: 1, name: "Saved trip", stops: [stop], version: 1 }];
  await page.goto("/plan/saved");
  await page.getByRole("button", { name: "Saved trip", exact: true }).click();
  await page.getByLabel("Trip name", { exact: true }).fill("Unsaved private edit");
  state.failSession = true;
  await page.evaluate(() => window.dispatchEvent(new Event("focus")));
  await expect(page.locator(".saved-trips").getByRole("alert")).toContainText("account could not be checked");
  await expect(page.getByLabel("Trip name", { exact: true })).toHaveValue("Unsaved private edit");
  state.failSession = false;
  await page.getByRole("button", { name: "Save itinerary", exact: true }).click();
  await expect(page.getByText("Itinerary saved to your account.", { exact: true })).toBeVisible();
});
