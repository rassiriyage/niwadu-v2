"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import type { Hotel } from "@/lib/admin-api";
import type { Room as LegacyRoom } from "../onboarding-recovery";
import { useOperations } from "../operations/forms";
import DraftForm, { Input, type SaveRegistry } from "./draft-form";
import Gallery from "./photos";
import Rates from "./rates";

export type Room = { id: number; name: string; max_occupancy: number; status: string; version: number; photos: { id: number; caption: string; url: string; position: number }[] };
export const meals = { RO: "Room Only", BB: "Bed & Breakfast", HB: "Half Board", FB: "Full Board" };
export async function flushRooms(registry: SaveRegistry) { for (const flush of registry.values()) await flush(); }

export default function RoomSetup({ hotel, storagePrefix, registry, mode, legacy, converted, convert, editLegacy }: {
  hotel: Hotel; storagePrefix: string; registry: SaveRegistry; mode: "rooms" | "rates";
  legacy: LegacyRoom[]; converted: boolean; convert: () => Promise<void>; editLegacy: (rooms: LegacyRoom[]) => void;
}) {
  const request = useOperations(), root = `hotels/${hotel.id}/room-types`;
  const selectionKey = `${storagePrefix}:selected-room`;
  const [rooms, setRooms] = useState<Room[]>(), [selected, setSelected] = useState<number | "new">(), [error, setError] = useState("");
  const latestRooms = useRef<Room[]>([]);
  const [epoch, setEpoch] = useState(0), [working, setWorking] = useState(false), [copy, setCopy] = useState<{ name: string; occupancy: string }>();
  const load = useCallback(async () => {
    const all: Room[] = [];
    let page = 1, last = 1;
    do { const result = await request<{ data: Room[]; meta: { last_page: number } }>(`${root}?per_page=50&page=${page}`); all.push(...result.data); last = result.meta.last_page; page++; } while (page <= last);
    latestRooms.current = all; setRooms(all); return all;
  }, [request, root]);
  useEffect(() => { let active = true; Promise.resolve().then(load).then(all => { if (active) { let choice: number | "new" | undefined = all[0]?.id; try { const saved = sessionStorage.getItem(selectionKey); if (saved === "new" && mode === "rooms") choice = "new"; else if (all.some(r => r.id === Number(saved))) choice = Number(saved); } catch {} setSelected(choice); } }).catch(e => { if (active) setError(e.message); }); return () => { active = false; }; }, [load, selectionKey, mode]);
  const room = rooms?.find(r => r.id === selected);
  async function choose(id: number | "new", duplicate?: Room) {
    try { await flushRooms(registry); setCopy(duplicate ? { name: `${duplicate.name} (copy)`, occupancy: String(duplicate.max_occupancy) } : undefined); setSelected(id); try { sessionStorage.setItem(selectionKey, String(id)); } catch {} setEpoch(e => e + 1); setError(""); }
    catch (e) { setError((e as Error).message); }
  }
  async function reload() { await load(); setEpoch(e => e + 1); }
  function update(updated: Room) { latestRooms.current = [...latestRooms.current.filter(r => r.id !== updated.id), updated].sort((a, b) => a.id - b.id); setRooms(latestRooms.current); setSelected(updated.id); try { sessionStorage.setItem(selectionKey, String(updated.id)); } catch {} }
  const needsConversion = !converted && legacy.length > 0;
  return <div className="wizard-room-setup">
    {error && <p role="alert" className="error">{error}</p>}
    {!rooms && !error && <p role="status">Loading saved room types…</p>}
    {needsConversion && <div className="notice"><h3>Continue your saved room drafts</h3><p>Confirm these room names and guest limits to continue with room photos and meal-plan prices. Previous indicative rates and quantities are retained as reference only.</p>
      {legacy.map((r, i) => <div className="form-grid" key={i}><label>Saved room {i + 1} name<input value={r.name ?? ""} onChange={e => editLegacy(legacy.map((old, n) => n === i ? { ...old, name: e.target.value } : old))} /></label><label>Saved room {i + 1} maximum guests<input type="number" min={1} max={30} value={r.occupancy ?? ""} onChange={e => editLegacy(legacy.map((old, n) => n === i ? { ...old, occupancy: e.target.value === "" ? null : Number(e.target.value) } : old))} /></label></div>)}
      {!!rooms?.length && <p>Catalog rooms already exist. Ask a Niwadu administrator to reconcile these older drafts before conversion; no rooms will be matched by name automatically.</p>}
      <button disabled={working || !!rooms?.length} onClick={async () => { setWorking(true); try { await convert(); await reload(); setError(""); } catch (e) { setError((e as Error).message); } finally { setWorking(false); } }}>{working ? "Saving…" : "Use these saved room drafts"}</button>
    </div>}
    {!needsConversion && rooms && <>
      <p>{mode === "rooms" ? "Add each room type and its own photographs here. Changes save automatically." : "Choose the meal plans and currencies this room offers. Enter each price independently; no currency conversion is applied."}</p>
      <div className="room-actions">{rooms.map(r => <button className="secondary" aria-pressed={selected === r.id} key={r.id} onClick={() => choose(r.id)}>{r.name}</button>)}{mode === "rooms" && <button onClick={() => choose("new")}>Add room type</button>}</div>
      {rooms.length === 0 && <p>No room types yet. {mode === "rates" ? "Add a room in step 3 first." : "Select Add room type to begin."}</p>}
      {mode === "rooms" && selected !== undefined && <div className="room-draft" key={`${selected}:${epoch}`}>
        <h3>{room?.name ?? "New room type"}</h3>
        <RoomDetails room={room} root={root} storageKey={`${storagePrefix}:room:${selected}`} registry={registry} update={update} reload={reload} copy={copy} />
        {room && <><button className="secondary" onClick={() => choose("new", room)}>Duplicate room details</button><p className="hint">Duplicating copies the name and guest limit only. Add the new room’s photographs and prices separately.</p><Gallery room={room} root={`${root}/${room.id}`} currentRoom={() => latestRooms.current.find(r => r.id === room.id)!} update={update} registry={registry} /></>}
      </div>}
      {mode === "rates" && room && <Rates key={`${room.id}:${epoch}`} room={room} root={`${root}/${room.id}`} storagePrefix={storagePrefix} registry={registry} hotel={hotel} />}
    </>}
  </div>;
}

function RoomDetails({ room, root, storageKey, registry, update, reload, copy }: { room?: Room; root: string; storageKey: string; registry: SaveRegistry; update: (room: Room) => void; reload: () => Promise<void>; copy?: { name: string; occupancy: string } }) {
  const request = useOperations();
  const [clientKey] = useState(() => crypto.randomUUID());
  return <DraftForm storageKey={storageKey} initial={{ name: room?.name ?? copy?.name ?? "", occupancy: String(room?.max_occupancy ?? copy?.occupancy ?? ""), client_key: clientKey }} version={room?.version ?? 0} registry={registry} replayCreate={!room} reload={reload} save={async (v, version) => {
    const result = await request<{ data: Room }>(room ? `${root}/${room.id}` : root, room ? "PUT" : "POST", { name: v.name, max_occupancy: v.occupancy === "" ? null : Number(v.occupancy), status: room?.status ?? "draft", ...(room ? { version } : { client_key: v.client_key }) }); update(result.data); return result.data.version;
  }}>{(values, change) => <><Input label="Room type name" name="name" values={values} change={change} maxLength={255} required /><Input label="Maximum guests" name="occupancy" values={values} change={change} type="number" min={1} max={30} required /></>}</DraftForm>;
}
