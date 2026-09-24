"use client";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { ApiError } from "@/lib/admin-api";
import { canLeaveOperations, Check, checked, Field, number, OperationForm, text, useOperations } from "./forms";
import type { Night, Plan } from "./types";

const money = (value?: number) => value === undefined ? "" : (value / 100).toFixed(2);
function minor(data: FormData, name: string) {
  const value = text(data, name);
  if (!/^\d+(\.\d{1,2})?$/.test(value)) throw new ApiError("Enter each LKR amount explicitly, using at most two decimal places. Use 0 only when no charge applies.", 422);
  const [whole, fraction = ""] = value.split(".");
  const result = Number(whole) * 100 + Number(fraction.padEnd(2, "0"));
  if (!Number.isSafeInteger(result) || result > 1_000_000_000) throw new ApiError("Each amount must be at most LKR 10,000,000.00.", 422);
  return result;
}
export default function Nights({ root, plans, editable }: { root: string; plans: Plan[]; editable: boolean }) {
  const request = useOperations();
  const [from, setFrom] = useState(new Date().toISOString().slice(0, 10)), [selectedDate, setSelectedDate] = useState(from), [planId, setPlanId] = useState<number | "">(plans[0]?.id ?? ""), [mode, setMode] = useState("stock");
  const [snapshot, setSnapshot] = useState<{ stock: Night[]; rates: Night[] }>(), [error, setError] = useState("");
  const to = new Date(`${from}T00:00:00Z`); to.setUTCDate(to.getUTCDate() + 7);
  const query = `from=${from}&to=${to.toISOString().slice(0, 10)}`;
  const load = useCallback(async () => {
    const [stock, rates] = await Promise.all([request<{ data: Night[] }>(`${root}/inventory-nights?${query}`), planId ? request<{ data: Night[] }>(`${root}/rate-plans/${planId}/nights?${query}`) : Promise.resolve({ data: [] })]);
    setSnapshot({ stock: stock.data, rates: rates.data }); setError("");
  }, [request, root, query, planId]);
  useEffect(() => { let active = true; Promise.all([request<{ data: Night[] }>(`${root}/inventory-nights?${query}`), planId ? request<{ data: Night[] }>(`${root}/rate-plans/${planId}/nights?${query}`) : Promise.resolve({ data: [] })]).then(([stock, rates]) => { if (active) { setSnapshot({ stock: stock.data, rates: rates.data }); setError(""); } }).catch(e => { if (active) setError(e.message); }); return () => { active = false; }; }, [request, root, query, planId]);
  function range(event: FormEvent<HTMLFormElement>) { event.preventDefault(); if (!canLeaveOperations()) return; const value = text(new FormData(event.currentTarget), "from"); setFrom(value); setSelectedDate(value); setSnapshot(undefined); }
  const stock = snapshot?.stock.find(n => n.stay_date === selectedDate), rate = snapshot?.rates.find(n => n.stay_date === selectedDate);
  return <><p>Enter known stock and complete prices for each date. Missing rows remain unconfigured. All rate plans share this room type’s inventory.</p>
    <form className="room-actions" onSubmit={range}><Field label="Show seven days from" name="from" type="date" defaultValue={from} min="2000-01-01" max="2099-12-24" required /><button>Load dates</button></form>
    <label>Rate plan<select value={planId} onChange={e => { if (canLeaveOperations()) { setPlanId(Number(e.target.value)); setSnapshot(undefined); } }}><option value="" disabled>Select a rate plan</option>{plans.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
    {error && <p className="error" role="alert">{error}</p>}{!snapshot && !error && <p role="status">Loading dated inventory…</p>}
    {snapshot && <><div className="ops-table-scroll" tabIndex={0} role="region" aria-label="Dated inventory overview"><table><thead><tr><th>Date</th><th>Capacity</th><th>Held / sold</th><th>Base price (LKR)</th><th>Charges</th></tr></thead><tbody>{snapshot.stock.map(n => { const r = snapshot.rates.find(r => r.stay_date === n.stay_date); return <tr key={n.stay_date}><th><button className="secondary" aria-pressed={selectedDate === n.stay_date} onClick={() => { if (canLeaveOperations()) setSelectedDate(n.stay_date); }}>{n.stay_date}</button></th><td>{n.configured ? n.capacity : "Not configured"}</td><td>{n.configured ? `${n.held} / ${n.sold}` : "—"}</td><td>{r?.configured ? money(r.base_minor) : "Not configured"}</td><td>{r?.configured ? r.mandatory_charges_complete ? "Complete" : "Incomplete" : "Unknown"}</td></tr>; })}</tbody></table></div>
      <h4>Edit {selectedDate}</h4><div className="room-actions"><button className="secondary" aria-pressed={mode === "stock"} onClick={() => { if (canLeaveOperations()) setMode("stock"); }}>Room stock</button><button className="secondary" disabled={!planId} aria-pressed={mode === "rate"} onClick={() => { if (canLeaveOperations()) setMode("rate"); }}>Price and restrictions</button></div>
      {mode === "stock" && stock && <OperationForm key={`stock:${selectedDate}:${stock.version}`} editable={editable} label="Save stock for this date" reload={load} save={async data => { await request(`${root}/inventory-nights/${selectedDate}`, "PUT", { version: stock.version ?? 0, capacity: number(data, "capacity") }); await load(); }}><Field label="Total allocated room capacity" name="capacity" type="number" min={(stock.held ?? 0) + (stock.sold ?? 0)} max={100000} step={1} defaultValue={stock.capacity} required /><p className="hint">Capacity includes held and sold rooms. It cannot be reduced below their combined count.</p></OperationForm>}
      {mode === "rate" && rate && planId && <OperationForm key={`rate:${planId}:${selectedDate}:${rate.version}`} editable={editable} label="Save price and restrictions" reload={load} save={async data => { await request(`${root}/rate-plans/${planId}/nights/${selectedDate}`, "PUT", { version: rate.version ?? 0, base_minor: minor(data, "base"), tax_minor: minor(data, "tax"), fee_minor: minor(data, "fee"), mandatory_charges_complete: checked(data, "complete"), stop_sell: checked(data, "stop"), min_stay: number(data, "min_stay"), max_stay: number(data, "max_stay"), closed_to_arrival: checked(data, "arrival"), closed_to_departure: checked(data, "departure") }); await load(); }}>
        <Field label="Base price (LKR)" name="base" inputMode="decimal" defaultValue={money(rate.base_minor)} required /><Field label="Tax (LKR)" name="tax" inputMode="decimal" defaultValue={money(rate.tax_minor)} required /><Field label="Mandatory fees (LKR)" name="fee" inputMode="decimal" defaultValue={money(rate.fee_minor)} required />
        <Field label="Minimum stay (nights)" name="min_stay" type="number" min={1} max={30} step={1} defaultValue={rate.min_stay} required /><Field label="Maximum stay (nights)" name="max_stay" type="number" min={1} max={30} step={1} defaultValue={rate.max_stay} required />
        <Check name="complete" label="All mandatory charges are included" value={rate.mandatory_charges_complete} /><Check name="stop" label="Stop sales for this date" value={rate.stop_sell ?? true} /><Check name="arrival" label="Closed to arrivals" value={rate.closed_to_arrival} /><Check name="departure" label="Closed to departures" value={rate.closed_to_departure} />
        <p className="full hint">Enter zero only for confirmed zero charges. Incomplete charges stop offers. Configure restrictions for checkout dates too: checkout nights are not charged, but departure restrictions are checked.</p>
      </OperationForm>}
    </>}
  </>;
}
