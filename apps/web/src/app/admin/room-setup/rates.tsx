"use client";

import { useCallback, useEffect, useState } from "react";
import { ApiError, type Hotel } from "@/lib/admin-api";
import { useOperations } from "../operations/forms";
import type { Pool } from "../operations/types";
import DraftForm, { Input, type SaveRegistry } from "./draft-form";
import { flushRooms, meals, type Room } from "./index";
import Price from "./price";

export type Plan = { id: number; name: string; status: string; currency: "LKR" | "USD"; meal_plan: keyof typeof meals | null; version: number; policy: { version: string | null; text: string | null } };

export default function Rates({ room, root, storagePrefix, registry, hotel }: { room: Room; root: string; storagePrefix: string; registry: SaveRegistry; hotel: Hotel }) {
  const request = useOperations();
  const selectionKey = `${storagePrefix}:selected-plan:${room.id}`;
  const [snapshot, setSnapshot] = useState<{ data: Plan[]; inventory_pool: Pool | null }>(), [chosen, setChosen] = useState<number>();
  const [error, setError] = useState(""), [busy, setBusy] = useState(false), [uncertain, setUncertain] = useState(false), [epoch, setEpoch] = useState(0);
  const load = useCallback(async () => { const result = await request<{ data: Plan[]; inventory_pool: Pool | null }>(`${root}/rate-plans`); setSnapshot(result); return result; }, [request, root]);
  useEffect(() => { let active = true; Promise.resolve().then(load).then(r => { if (active) { let choice = r.data[0]?.id; try { const id = Number(sessionStorage.getItem(selectionKey)); if (r.data.some(p => p.id === id)) choice = id; } catch {} setChosen(choice); } }).catch(e => { if (active) setError(e.message); }); return () => { active = false; }; }, [load, selectionKey]);
  const plan = snapshot?.data.find(p => p.id === chosen), editable = !!hotel.permissions.author_rates && snapshot?.inventory_pool?.owner !== "pms";
  function remember(id: number) { setChosen(id); try { sessionStorage.setItem(selectionKey, String(id)); } catch {} }
  async function choose(id: number) { try { await flushRooms(registry); remember(id); setError(""); } catch (e) { setError((e as Error).message); } }
  async function add(meal_plan: keyof typeof meals, currency: "LKR" | "USD") {
    setBusy(true); setError("");
    try {
      await flushRooms(registry);
      const result = await request<{ data: Plan }>(`${root}/rate-plans`, "POST", { name: `${meals[meal_plan]} · ${currency}`, meal_plan, currency, status: "draft", policy: { version: null, text: null } });
      await load(); remember(result.data.id);
    } catch (e) { setError((e as Error).message); setUncertain(!(e instanceof ApiError) || e.status === 409 || e.status >= 500 || e.status === 408); }
    finally { setBusy(false); }
  }
  async function reconcile() { setBusy(true); try { await load(); setUncertain(false); setError(""); } catch (e) { setError((e as Error).message); } finally { setBusy(false); } }
  async function reload() { await load(); setEpoch(n => n + 1); }
  return <div className="room-draft"><h3>{room.name} · meal plans and rates</h3>
    <p>All meal plans and currencies share this room type’s stock. Saving draft prices does not allocate inventory or open sales.</p>
    {error && <p role="alert" className="error">{error}</p>}
    {!snapshot && !error && <p role="status">Loading meal plans…</p>}
    {snapshot && <>
      {snapshot.inventory_pool?.owner === "pms" && <p className="notice">This room uses PMS inventory. The Niwadu team must configure its provider rates; manual prices cannot be entered here.</p>}
      {!snapshot.inventory_pool && <p className="hint">You can save draft prices now. A Niwadu administrator must separately confirm inventory ownership and sales readiness.</p>}
      <div className="meal-options">{Object.entries(meals).map(([code, label]) => <section key={code}><h4>{label} ({code})</h4><div className="room-actions">{(["LKR", "USD"] as const).map(currency => { const existing = snapshot.data.find(p => p.meal_plan === code && p.currency === currency); return existing ? <button key={currency} className="secondary" aria-pressed={chosen === existing.id} disabled={busy} onClick={() => choose(existing.id)}>{currency} · {existing.status === "archived" ? "Archived" : "Edit prices"}</button> : <button key={currency} className="secondary" disabled={!editable || busy || uncertain} onClick={() => add(code as keyof typeof meals, currency)}>Add {currency} prices</button>; })}</div></section>)}</div>
      {snapshot.data.some(p => p.meal_plan === null) && <div className="notice"><p>Existing offers with no recorded meal plan remain separate.</p>{snapshot.data.filter(p => p.meal_plan === null).map(p => <button className="secondary" key={p.id} onClick={() => choose(p.id)}>{p.name} · {p.currency} · Meal plan unspecified</button>)}</div>}
      {uncertain && <div className="notice"><p>This offer may already have been saved. Reload the offer list and use the existing meal-plan/currency offer before trying to add one.</p><button disabled={busy} onClick={reconcile}>Reload saved offers</button></div>}
      {plan && <div key={`${plan.id}:${epoch}`}><h4>{plan.meal_plan ? meals[plan.meal_plan] : plan.name} · {plan.currency}</h4>
        <DraftForm storageKey={`${storagePrefix}:plan:${plan.id}`} version={plan.version} initial={{ policy_version: plan.policy?.version ?? "", policy_text: plan.policy?.text ?? "", status: plan.status }} registry={registry} reload={reload} editable={editable && (hotel.permissions.manage_inventory || plan.status === "draft")} save={async (v, version) => {
          const result = await request<{ data: Plan }>(`${root}/rate-plans/${plan.id}`, "PUT", { name: plan.name, currency: plan.currency, meal_plan: plan.meal_plan, status: v.status, policy: { version: v.policy_version || null, text: v.policy_text || null }, version });
          setSnapshot(current => current && ({ ...current, data: current.data.map(p => p.id === result.data.id ? result.data : p) })); return result.data.version;
        }}>{(values, change) => <>
          <Input label="Policy revision label" name="policy_version" values={values} change={change} maxLength={100} />
          <label>Cancellation and rate policy<textarea value={String(values.policy_text)} onChange={e => change("policy_text", e.target.value)} maxLength={10000} rows={4} /></label>
          <p className="hint full">You can leave policy details blank while collecting them. Offers remain unavailable until all required facts and activation checks are complete.</p>
          {hotel.permissions.manage_inventory && <label>Offer status<select value={String(values.status)} onChange={e => change("status", e.target.value)}><option value="draft">Draft</option><option value="active">Active</option><option value="archived">Archived</option></select></label>}
        </>}</DraftForm>
        <Price plan={plan} root={root} storagePrefix={storagePrefix} registry={registry} editable={editable && (hotel.permissions.manage_inventory || plan.status === "draft")} />
      </div>}
    </>}
  </div>;
}
