"use client";
import { useCallback, useEffect, useState } from "react";
import type { Hotel } from "@/lib/admin-api";
import { canLeaveOperations, Field, number, OperationForm, Status, text, useOperations } from "./forms";
import type { Page, Plan, Pool, Room } from "./types";
import Nights from "./nights";

export default function Manual({ hotel }: { hotel: Hotel }) {
  const request = useOperations();
  const [rooms, setRooms] = useState<Room[]>(), [page, setPage] = useState(1), [last, setLast] = useState(1), [selected, setSelected] = useState<Room | null>(), [step, setStep] = useState("room"), [error, setError] = useState("");
  const root = `hotels/${hotel.id}/room-types`;
  const load = useCallback(async () => { const result = await request<Page<Room>>(`${root}?page=${page}`); setRooms(result.data); setLast(result.meta.last_page); }, [request, root, page]);
  useEffect(() => { let active = true; request<Page<Room>>(`${root}?page=${page}`).then(r => { if (active) { setRooms(r.data); setLast(r.meta.last_page); setError(""); } }).catch(e => { if (active) setError(e.message); }); return () => { active = false; }; }, [request, root, page]);
  async function reload() { await load(); if (selected) setSelected((await request<{ data: Room }>(`${root}/${selected.id}`)).data); }
  return <section className="panel"><h2>Rooms, rates and availability</h2><p>Set up each room type in order. Draft room notes are separate from this catalog; enter and confirm the real room details here.</p>
    {error && <p role="alert" className="error">{error}</p>}
    {!rooms && !error && <p role="status">Loading room types…</p>}
    {rooms?.length === 0 && <p>No room types have been configured.</p>}
    <div className="room-actions">{rooms?.map(room => <button key={room.id} className="secondary" aria-pressed={selected?.id === room.id} onClick={() => { if (canLeaveOperations()) { setSelected(room); setStep("room"); } }}>{room.name}</button>)}
      {hotel.permissions.edit_profile && <button onClick={() => { if (canLeaveOperations()) { setSelected(null); setStep("room"); } }}>Add room type</button>}
    </div>
    {last > 1 && <nav className="pagination" aria-label="Room type pages"><button disabled={page === 1} onClick={() => { if (canLeaveOperations()) { setPage(page - 1); setSelected(undefined); } }}>Previous rooms</button><span>{page} / {last}</span><button disabled={page === last} onClick={() => { if (canLeaveOperations()) { setPage(page + 1); setSelected(undefined); } }}>Next rooms</button></nav>}
    {selected !== undefined && <div className="room-draft" key={selected?.id ?? "new"}>
      <h3>{selected?.name ?? "New room type"}</h3>
      {selected && <nav className="setup-steps" aria-label="Room configuration">{[["room", "1. Room details"], ["pool", "2. Inventory ownership"], ["plans", "3. Rate plans"], ["nights", "4. Dated stock and prices"]].map(([key, label]) => <button key={key} className="secondary" aria-current={step === key ? "step" : undefined} onClick={() => { if (canLeaveOperations()) setStep(key); }}>{label}</button>)}</nav>}
      {step === "room" && <OperationForm key={`room:${selected?.id}:${selected?.version}`} label={selected ? "Save room details" : "Create room type"} editable={hotel.permissions.edit_profile} reload={reload} save={async data => {
        const result = await request<{ data: Room }>(selected ? `${root}/${selected.id}` : root, selected ? "PUT" : "POST", { name: text(data, "name"), max_occupancy: number(data, "max_occupancy"), status: text(data, "status"), ...(selected ? { version: selected.version } : {}) });
        setSelected(result.data); await load();
      }}><Field label="Room type name" name="name" defaultValue={selected?.name} required maxLength={255} /><Field label="Maximum guests per room" name="max_occupancy" type="number" min={1} max={30} step={1} defaultValue={selected?.max_occupancy} required /><Status value={selected?.status} /><p className="hint">Active marks catalog content as usable. It does not publish the hotel or guarantee bookable inventory.</p></OperationForm>}
      {selected && step !== "room" && <RoomConfiguration key={`${selected.id}:${step}`} hotel={hotel} room={selected} step={step} />}
    </div>}
  </section>;
}
function RoomConfiguration({ hotel, room, step }: { hotel: Hotel; room: Room; step: string }) {
  const request = useOperations(), root = `hotels/${hotel.id}/room-types/${room.id}`;
  const [snapshot, setSnapshot] = useState<{ data: Plan[]; inventory_pool: Pool | null }>(), [chosen, setChosen] = useState<number | "new">("new"), [error, setError] = useState("");
  const load = useCallback(async () => { setSnapshot(await request(`${root}/rate-plans`)); }, [request, root]);
  useEffect(() => { let active = true; request<{ data: Plan[]; inventory_pool: Pool | null }>(`${root}/rate-plans`).then(r => { if (active) setSnapshot(r); }).catch(e => { if (active) setError(e.message); }); return () => { active = false; }; }, [request, root]);
  if (!snapshot) return <p role={error ? "alert" : "status"}>{error || "Loading room configuration…"}</p>;
  const pool = snapshot.inventory_pool, plan = snapshot.data.find(p => p.id === chosen);
  if (step === "pool") return <><p>Only a Niwadu administrator can establish manual inventory ownership. A disconnected PMS must never be converted to manual inventory here.</p>
    <p>Current ownership: <strong>{pool?.owner ?? "Not configured"}</strong></p>
    {pool && !["manual", "unconfigured"].includes(pool.owner) ? <p className="notice">This room uses {pool.owner} inventory. Contact the Niwadu team to review its provider configuration.</p> : <OperationForm key={pool?.version ?? 0} label="Save manual inventory configuration" editable={hotel.permissions.manage_pms} reload={load} save={async data => { await request(`${root}/inventory-pool`, "PUT", { owner: "manual", sales_state: text(data, "sales_state"), timezone: text(data, "timezone"), version: pool?.version ?? 0 }); await load(); }}>
      <Field label="Hotel timezone (IANA name)" name="timezone" defaultValue={pool?.timezone ?? "Asia/Colombo"} required /><label>Sales state<select name="sales_state" defaultValue={pool?.sales_state ?? "closed"}><option value="closed">Closed</option><option value="open">Open for eligible dated offers</option></select></label>
      <label className="ops-check full"><input type="checkbox" required />I confirm Niwadu is authorized to manage this room’s allocated inventory manually.</label>
    </OperationForm>}</>;
  if (pool?.owner !== "manual") return <p className="notice">A platform administrator must configure manual inventory ownership before rates or stock can be entered.</p>;
  if (step === "nights") return <Nights root={root} plans={snapshot.data} editable={hotel.permissions.manage_inventory} />;
  return <><label>Rate plan<select value={chosen} onChange={e => { if (canLeaveOperations()) setChosen(e.target.value === "new" ? "new" : Number(e.target.value)); }}><option value="new">New rate plan</option>{snapshot.data.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
    <OperationForm key={`${chosen}:${plan?.version}`} label={plan ? "Save rate plan" : "Create rate plan"} editable={hotel.permissions.manage_inventory} reload={load} save={async data => { const result = await request<{ data: Plan }>(`${root}/rate-plans${plan ? `/${plan.id}` : ""}`, plan ? "PUT" : "POST", { name: text(data, "name"), status: text(data, "status"), currency: plan?.currency ?? text(data, "currency"), meal_plan: plan ? plan.meal_plan : text(data, "meal_plan") || null, policy: { version: text(data, "policy_version") || null, text: text(data, "policy_text") || null }, ...(plan ? { version: plan.version } : {}) }); setChosen(result.data.id); await load(); }}>
      <Field label="Rate plan name" name="name" defaultValue={plan?.name} required maxLength={255} /><Status value={plan?.status} /><Field label="Policy revision label" name="policy_version" defaultValue={plan?.policy.version ?? undefined} maxLength={100} />{plan ? <p>Currency: {plan.currency} · Meal plan: {plan.meal_plan ?? "Unspecified"}</p> : <><label>Currency<select name="currency" defaultValue="LKR"><option value="LKR">LKR</option><option value="USD">USD</option></select></label><label>Meal plan<select name="meal_plan" defaultValue=""><option value="">Unspecified</option><option value="RO">Room Only</option><option value="BB">Bed &amp; Breakfast</option><option value="HB">Half Board</option><option value="FB">Full Board</option></select></label></>}<label className="full">Cancellation and rate policy<textarea name="policy_text" defaultValue={plan?.policy.text ?? undefined} maxLength={10000} rows={5} /></label>
    </OperationForm></>;
}
