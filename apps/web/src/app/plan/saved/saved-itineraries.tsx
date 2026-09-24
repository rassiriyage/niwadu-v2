"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import { FreshLink } from "../../fresh-link";
import { getTravellerSession, itineraryRequest, TravellerError, type Traveller } from "../../../lib/traveller-api";
import { PlannerEditor, type Stop } from "../trip-planner";
import destinations from "../destinations.json";

type Itinerary = { id: number; name: string; stops: Stop[]; version: number; created_at: string; updated_at: string };
type Listing = { data: Itinerary[]; meta: { current_page: number; last_page: number; total: number } };
const slugs = new Set(destinations.map(item => item.slug));
function validStops(value: unknown): value is Stop[] {
  return Array.isArray(value) && value.length <= 20 && new Set(value.map(stop => stop?.slug)).size === value.length && value.every(stop => stop && slugs.has(stop.slug) && Number.isInteger(stop.nights) && stop.nights >= 1 && stop.nights <= 14);
}
function checked(value: Itinerary): Itinerary {
  if (!value || !Number.isSafeInteger(value.id) || value.id < 1 || !Number.isSafeInteger(value.version) || value.version < 1 || typeof value.name !== "string" || !validStops(value.stops)) throw new TravellerError("This saved itinerary could not be read.", 503);
  return value;
}
function checkedList(value: Listing) {
  if (!value || !Array.isArray(value.data) || !Number.isSafeInteger(value.meta?.current_page) || value.meta.current_page < 1 || !Number.isSafeInteger(value.meta.last_page) || value.meta.last_page < 1 || !Number.isSafeInteger(value.meta.total) || value.meta.total < 0) throw new TravellerError("The saved-trip list could not be read.", 503);
  value.data.forEach(checked); return value;
}
export function SavedItineraries() {
  const [user, setUser] = useState<Traveller | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [listing, setListing] = useState<Listing | null>(null);
  const [draft, setDraft] = useState<{ id?: number; version?: number; name: string; stops: Stop[] } | null>(null);
  const [editorKey, setEditorKey] = useState(0);
  const [dirty, setDirty] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [blocked, setBlocked] = useState<"create" | "update" | "delete" | null>(null);
  const [latest, setLatest] = useState<Itinerary | null>(null);
  const pendingCreate = useRef<{ key: string; payload: { name: string; stops: Stop[] } } | null>(null);
  const epoch = useRef(0);
  const identity = useRef<number | null>(null);
  const clearPrivate = useCallback(() => { epoch.current++; pendingCreate.current = null; identity.current = null; setUser(null); setListing(null); setDraft(null); setLatest(null); setDirty(false); setBlocked(null); setBusy(false); setMessage(""); }, []);
  useEffect(() => {
    let active = true;
    const inspect = async () => {
      const current = epoch.current;
      try {
        const session = await getTravellerSession();
        if (!active || current !== epoch.current) return;
        if (identity.current !== null && identity.current !== session.user?.id) { clearPrivate(); setError("The signed-in account changed. Reload this page to continue."); return; }
        const initial = identity.current === null;
        identity.current = session.user?.id ?? null; setUser(session.user);
        if (initial && session.user) {
          const value = await itineraryRequest<Listing>("?page=1", session.user.id);
          if (active && current === epoch.current && identity.current === session.user.id) setListing(checkedList(value));
        }
      } catch (failure) { if (active && current === epoch.current) { if (failure instanceof TravellerError && [401, 419].includes(failure.status)) clearPrivate(); setError("Your account could not be checked. Refresh saved trips to try again."); } }
      finally { if (active) setLoading(false); }
    };
    void inspect();
    const visible = () => { if (document.visibilityState === "visible") void inspect(); };
    window.addEventListener("focus", inspect); window.addEventListener("pageshow", inspect); document.addEventListener("visibilitychange", visible);
    return () => { active = false; window.removeEventListener("focus", inspect); window.removeEventListener("pageshow", inspect); document.removeEventListener("visibilitychange", visible); };
  }, [clearPrivate]);
  useEffect(() => { const warn = (event: BeforeUnloadEvent) => { if (dirty) event.preventDefault(); }; window.addEventListener("beforeunload", warn); return () => window.removeEventListener("beforeunload", warn); }, [dirty]);
  async function run<T>(job: () => Promise<T>, success: (value: T) => void, mutation?: "create" | "update" | "delete") {
    if (busy) return;
    const current = epoch.current; setBusy(true); setError(""); setMessage("");
    try { const value = await job(); if (current === epoch.current) success(value); }
    catch (failure) {
      if (current !== epoch.current) return;
      if (failure instanceof TravellerError && [401, 419].includes(failure.status)) clearPrivate();
      else if (mutation && (!(failure instanceof TravellerError) || failure.status === 0 || failure.status >= 500 || failure.status === 409)) setBlocked(mutation);
      setError(failure instanceof Error ? failure.message : "The request could not be completed.");
    } finally { if (current === epoch.current) setBusy(false); }
  }
  function list(page = 1) {
    if (!user) return;
    void run(() => itineraryRequest<Listing>(`?page=${page}`, user.id), value => {
      setListing(checkedList(value));
    });
  }
  function replace(value: typeof draft) { pendingCreate.current = null; setDraft(value); setEditorKey(key => key + 1); setDirty(false); setBlocked(null); setLatest(null); }
  function discardAllowed() { return !dirty || window.confirm("Discard unsaved changes to this itinerary?"); }
  function open(id: number) {
    if (!user || !discardAllowed()) return;
    void run(() => itineraryRequest<{ data: Itinerary }>(`/${id}`, user.id), value => replace(checked(value.data)));
  }
  function create(copy: boolean) {
    if (!discardAllowed()) return;
    try {
      let stops: Stop[] = [];
      if (copy) { const raw = localStorage.getItem("niwadu.trip-draft.v1"); if (!raw || raw.length > 10000) throw new Error("No readable browser draft is available."); const value = JSON.parse(raw); if (value.version !== 1 || !validStops(value.stops)) throw new Error("This browser draft could not be read."); stops = value.stops; }
      replace({ name: "Untitled trip", stops }); setDirty(true); setError("");
    } catch (failure) { setError(failure instanceof Error ? failure.message : "The browser draft could not be read."); }
  }
  function save(event: React.FormEvent) {
    event.preventDefault(); if (busy || !user || !draft || blocked) return;
    const name = draft.name.trim();
    if (!name || [...name].length > 120) { setError("Use a trip name from 1 to 120 characters."); return; }
    if (!draft.id) {
      pendingCreate.current = { key: crypto.randomUUID(), payload: { name, stops: draft.stops.map(stop => ({ ...stop })) } };
      retryCreate(); return;
    }
    void run(() => itineraryRequest<{ data: Itinerary }>(`/${draft.id}`, user.id, "PUT", { name, stops: draft.stops, version: draft.version }), value => { replace(checked(value.data)); setListing(null); setMessage("Itinerary saved to your account."); }, "update");
  }
  function retryCreate() {
    const pending = pendingCreate.current;
    if (!user || !pending) return;
    void run(async () => {
      const created = await itineraryRequest<{ data: Itinerary }>("", user.id, "POST", pending.payload, pending.key);
      const id = checked(created.data).id;
      try { return checked((await itineraryRequest<{ data: Itinerary }>(`/${id}`, user.id)).data); }
      catch (failure) { if (failure instanceof TravellerError && failure.status === 404) return null; throw failure; }
    }, value => { replace(value); setListing(null); setMessage(value ? "Itinerary saved to your account." : "The original save completed, but that itinerary is no longer available. No new copy was created."); }, "create");
  }
  function remove() {
    if (!user || !draft?.id || blocked || !window.confirm(`Delete “${draft.name}” from your account? This cannot be undone.`)) return;
    void run(() => itineraryRequest<void>(`/${draft.id}`, user.id, "DELETE", { version: draft.version }), () => { replace(null); setListing(null); setMessage("Itinerary deleted."); }, "delete");
  }
  function compare() {
    if (!user || !draft?.id) return;
    void run(() => itineraryRequest<{ data: Itinerary }>(`/${draft.id}`, user.id), value => setLatest(checked(value.data)));
  }
  if (loading) return <p role="status">Checking your account…</p>;
  return <section className="saved-trips">
    <FreshLink className="planner-home" href="/plan">Open browser-only planner</FreshLink>
    {error && <p role="alert" className="planner-error">{error}</p>}{message && <p role="status">{message}</p>}
    {!user ? <p><FreshLink className="primary" href="/account">Sign in</FreshLink> to manage private saved trips. Your browser draft remains on this device.</p> : <>
      <p>Signed in as {user.name}. Account trips are never saved to this browser’s draft storage.</p>
      <div className="planner-actions"><button disabled={busy} className="planner-secondary" onClick={() => list()}>Refresh saved trips</button><button disabled={busy || Boolean(blocked)} className="planner-secondary" onClick={() => create(false)}>New itinerary</button><button disabled={busy || Boolean(blocked)} className="planner-secondary" onClick={() => create(true)}>Copy browser draft</button></div>
      {listing && <section aria-label="Saved itineraries"><p>{listing.meta.total} saved trips</p><ul>{listing.data.map(item => <li key={item.id}><button disabled={busy} className="planner-secondary" onClick={() => open(item.id)}>{item.name}</button><span>{item.stops.length} stops</span></li>)}</ul><div className="planner-actions"><button disabled={busy || listing.meta.current_page <= 1} onClick={() => list(listing.meta.current_page - 1)}>Previous saved trips</button><span>Page {listing.meta.current_page} of {listing.meta.last_page}</span><button disabled={busy || listing.meta.current_page >= listing.meta.last_page} onClick={() => list(listing.meta.current_page + 1)}>Next saved trips</button></div></section>}
      {blocked && <section className="planner-error" aria-label="Save recovery"><h2>Review before trying again</h2>{blocked === "create" ? <><p>The save may have completed, but we could not confirm it. Retry the same save with the original name and stops; this will not create a duplicate. You can also refresh saved trips to inspect them.</p><button disabled={busy} onClick={retryCreate}>Retry original save</button></> : <><p>Your edits remain here. The saved itinerary may have changed. Compare it before deciding what to save or delete.</p><button disabled={busy} onClick={compare}>Compare saved itinerary</button>{latest && <><h3>Saved version: {latest.name}</h3><p>{latest.stops.map(stop => `${stop.slug}: ${stop.nights} nights`).join(" · ") || "No stops"}</p><button disabled={busy} onClick={() => replace(latest)}>Use saved itinerary</button><button disabled={busy} onClick={() => { setDraft(current => current ? { ...current, version: latest.version } : current); setLatest(null); setBlocked(null); setDirty(true); }}>Keep my edits after review</button></>}</>}</section>}
      {draft && <><form className="saved-name" onSubmit={save}><label>Trip name<input value={draft.name} maxLength={240} required disabled={busy || blocked === "create"} onChange={event => { setDraft({ ...draft, name: event.target.value }); setDirty(true); }} /></label><button className="primary" disabled={busy || Boolean(blocked)}>Save itinerary</button>{draft.id && <button type="button" className="planner-secondary" disabled={busy || Boolean(blocked)} onClick={remove}>Delete itinerary</button>}<button type="button" className="planner-secondary" disabled={busy} onClick={() => { if (discardAllowed()) replace(null); }}>Close itinerary</button><p role="status">{dirty ? "Unsaved changes" : "Saved version loaded"}</p></form><fieldset className="saved-editor" disabled={busy || blocked === "create"}><legend className="visually-hidden">Edit saved itinerary</legend><PlannerEditor key={editorKey} account={{ stops: draft.stops, onChange: stops => { setDraft(current => current ? { ...current, stops } : current); setDirty(true); } }} /></fieldset></>}
    </>}
  </section>;
}
