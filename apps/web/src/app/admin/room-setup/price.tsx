"use client";

import { useCallback, useEffect, useState } from "react";
import { ApiError } from "@/lib/admin-api";
import { useOperations } from "../operations/forms";
import DraftForm, { Input, type SaveRegistry } from "./draft-form";
import { flushRooms } from "./index";
import type { Plan } from "./rates";

type Night = { configured: boolean; version?: number; base_minor?: number | null; tax_minor?: number | null; fee_minor?: number | null; min_stay?: number; max_stay?: number; mandatory_charges_complete?: boolean; stop_sell?: boolean; closed_to_arrival?: boolean; closed_to_departure?: boolean };
const money = (value?: number | null) => value == null ? "" : (value / 100).toFixed(2);
function minor(value: string | boolean) {
  if (value === "") return null;
  if (typeof value !== "string" || !/^\d+(\.\d{1,2})?$/.test(value)) throw new ApiError("Enter a nonnegative price with at most two decimal places, or leave it blank if unknown.", 422);
  const [whole, fraction = ""] = value.split(".");
  const result = Number(whole) * 100 + Number(fraction.padEnd(2, "0"));
  if (!Number.isSafeInteger(result) || result > 1000000000) throw new ApiError("Each price component must be at most 10,000,000.00.", 422);
  return result;
}

export default function Price({ plan, root, storagePrefix, registry, editable }: { plan: Plan; root: string; storagePrefix: string; registry: SaveRegistry; editable: boolean }) {
  const request = useOperations(), dateKey = `${storagePrefix}:date:${plan.id}`;
  const [date, setDate] = useState(() => { try { const saved = sessionStorage.getItem(dateKey); if (saved && /^20\d\d-\d\d-\d\d$/.test(saved) && !isNaN(Date.parse(saved))) return saved; } catch {} return new Date().toISOString().slice(0, 10); });
  const [error, setError] = useState("");
  async function choose(value: string) {
    if (!value || isNaN(Date.parse(value))) return;
    try { await flushRooms(registry); setDate(value); try { sessionStorage.setItem(dateKey, value); } catch {} setError(""); }
    catch (e) { setError((e as Error).message); }
  }
  return <><label>Price date<input type="date" min="2000-01-01" max="2099-12-30" value={date} onChange={e => choose(e.target.value)} /></label>{error && <p role="alert" className="error">{error}</p>}<NightForm key={`${plan.id}:${date}`} plan={plan} date={date} root={root} storagePrefix={storagePrefix} registry={registry} editable={editable} request={request} /></>;
}

function NightForm({ plan, date, root, storagePrefix, registry, editable, request }: { plan: Plan; date: string; root: string; storagePrefix: string; registry: SaveRegistry; editable: boolean; request: ReturnType<typeof useOperations> }) {
  const [night, setNight] = useState<Night>(), [error, setError] = useState(""), [epoch, setEpoch] = useState(0);
  const endpoint = `${root}/rate-plans/${plan.id}/nights`;
  const load = useCallback(async () => { const to = new Date(`${date}T00:00:00Z`); to.setUTCDate(to.getUTCDate() + 1); const result = await request<{ data: Night[] }>(`${endpoint}?from=${date}&to=${to.toISOString().slice(0, 10)}`); setNight(result.data[0]); }, [request, endpoint, date]);
  useEffect(() => { void Promise.resolve().then(load).catch(e => setError(e.message)); }, [load]);
  if (!night) return <p role={error ? "alert" : "status"}>{error || "Loading saved prices…"}</p>;
  return <DraftForm key={epoch} storageKey={`${storagePrefix}:night:${plan.id}:${date}`} initial={{ base: money(night.base_minor), tax: money(night.tax_minor), fee: money(night.fee_minor), complete: !!night.mandatory_charges_complete, stop: night.stop_sell === undefined ? true : !!night.stop_sell, min: String(night.min_stay ?? 1), max: String(night.max_stay ?? 30), arrival: !!night.closed_to_arrival, departure: !!night.closed_to_departure }} version={night.version ?? 0} registry={registry} editable={editable} reload={async () => { await load(); setEpoch(n => n + 1); }} save={async (v, version) => {
    await request(`${endpoint}/${date}`, "PUT", { version, base_minor: minor(v.base), tax_minor: minor(v.tax), fee_minor: minor(v.fee), mandatory_charges_complete: v.complete, stop_sell: v.stop, min_stay: Number(v.min), max_stay: Number(v.max), closed_to_arrival: v.arrival, closed_to_departure: v.departure });
    await load(); return version + 1;
  }}>{(values, change) => <>
    <Input label={`Base price (${plan.currency})`} name="base" values={values} change={change} inputMode="decimal" />
    <Input label={`Tax (${plan.currency})`} name="tax" values={values} change={change} inputMode="decimal" />
    <Input label={`Mandatory fees (${plan.currency})`} name="fee" values={values} change={change} inputMode="decimal" />
    <p className="full hint">Blank means unknown. Enter 0 only when a charge is confirmed to be zero. {plan.currency} amounts are independent of any other currency.</p>
    <Input label="Minimum stay (nights)" name="min" values={values} change={change} type="number" min={1} max={30} required />
    <Input label="Maximum stay (nights)" name="max" values={values} change={change} type="number" min={1} max={30} required />
    {[["complete", "All mandatory charges are known and included"], ["stop", "Stop sales for this date"], ["arrival", "Closed to arrivals"], ["departure", "Closed to departures"]].map(([key, label]) => <label className="ops-check" key={key}><input type="checkbox" checked={!!values[key]} onChange={e => change(key, e.target.checked)} />{label}</label>)}
    <p className="full hint">Configure every offered night and the checkout date’s restrictions. Checkout nights are not charged. Missing dates or incomplete charges remain unavailable.</p>
  </>}</DraftForm>;
}
