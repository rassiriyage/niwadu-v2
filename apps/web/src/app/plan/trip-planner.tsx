"use client";

import { useRef, useState, useSyncExternalStore } from "react";
import { SriLankaMap } from "../SriLankaMap";
import destinations from "./destinations.json";

type Stop = { slug: string; nights: number };
const KEY = "niwadu.trip-draft.v1";
const LIMIT = 20;
const bySlug = new Map(destinations.map(destination => [destination.slug, destination]));
const titles = ["Where do you want to go?", "How long at each stop?", "Your itinerary"];
const subscribe = () => () => {};

function restore(): { stops: Stop[]; error: string } {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return { stops: [], error: "" };
    if (raw.length > 10000) throw new Error("Invalid draft");
    const saved = JSON.parse(raw);
    if (saved.version !== 1 || !Array.isArray(saved.stops) || saved.stops.length > LIMIT) throw new Error("Invalid draft");
    const seen = new Set<string>();
    const stops = saved.stops.map((stop: Stop) => {
      if (!stop || !bySlug.has(stop.slug) || seen.has(stop.slug) || !Number.isInteger(stop.nights) || stop.nights < 1 || stop.nights > 14) throw new Error("Invalid stop");
      seen.add(stop.slug);
      return { slug: stop.slug, nights: stop.nights };
    });
    return { stops, error: "" };
  } catch {
    return { stops: [], error: "Your saved draft could not be opened. You can start a new itinerary here." };
  }
}

// Render the editor only after hydration so saved browser data never disagrees with server HTML.
export function TripPlanner() {
  const ready = useSyncExternalStore(subscribe, () => true, () => false);
  return ready ? <PlannerEditor /> : <p role="status">Opening your trip planner…</p>;
}

function PlannerEditor() {
  const [draft, setDraft] = useState(restore);
  const [step, setStep] = useState(0);
  const [query, setQuery] = useState("");
  const [message, setMessage] = useState("");
  const [saved, setSaved] = useState(draft.stops.length > 0);
  const [confirmClear, setConfirmClear] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const stopList = useRef<HTMLOListElement>(null);
  const search = useRef<HTMLInputElement>(null);
  const stops = draft.stops;
  const total = stops.reduce((sum, stop) => sum + stop.nights, 0);
  const available = destinations.filter(destination => !stops.some(stop => stop.slug === destination.slug));
  const results = available.filter(destination => destination.name.toLowerCase().includes(query.trim().toLowerCase()));
  const visible = query.trim() ? results : results.slice(0, 12);
  const points = stops.map(stop => bySlug.get(stop.slug)!);

  function update(next: Stop[], announcement: string) {
    let error = "";
    try { localStorage.setItem(KEY, JSON.stringify({ version: 1, stops: next })); setSaved(true); }
    catch { error = "This itinerary could not be saved in this browser. You can keep editing and download it before leaving."; setSaved(false); }
    setDraft({ stops: next, error });
    setMessage(announcement);
  }
  function go(next: number) {
    setStep(next);
    requestAnimationFrame(() => heading.current?.focus());
  }
  function add(slug: string) {
    if (stops.length >= LIMIT || stops.some(stop => stop.slug === slug)) return;
    update([...stops, { slug, nights: 2 }], `${bySlug.get(slug)!.name} added. ${stops.length + 1} stops.`);
    setQuery("");
    search.current?.focus();
  }
  function remove(index: number) {
    const next = stops.filter((_, i) => i !== index);
    update(next, `${bySlug.get(stops[index].slug)!.name} removed.`);
    requestAnimationFrame(() => {
      if (step === 0) search.current?.focus();
      else if (next.length) stopList.current?.children[Math.min(index, next.length - 1)]?.querySelector<HTMLButtonElement>("[data-remove]")?.focus();
      else heading.current?.focus();
    });
  }
  function move(index: number, direction: number) {
    const next = [...stops];
    const target = index + direction;
    if (target < 0 || target >= next.length) return;
    [next[index], next[target]] = [next[target], next[index]];
    update(next, `${bySlug.get(stops[index].slug)!.name} moved to stop ${target + 1}.`);
    requestAnimationFrame(() => stopList.current?.children[target]?.querySelector<HTMLButtonElement>(target === 0 ? "[data-later]" : "[data-earlier]")?.focus());
  }
  function nights(index: number, change: number) {
    update(stops.map((stop, i) => i === index ? { ...stop, nights: Math.max(1, Math.min(14, stop.nights + change)) } : stop), "Nights updated.");
  }
  function download() {
    const text = `My Sri Lanka itinerary\n${total} nights · ${stops.length} stops\n\n${stops.map((stop, i) => `${i + 1}. ${bySlug.get(stop.slug)!.name}: ${stop.nights} night${stop.nights === 1 ? "" : "s"}`).join("\n")}\n\nPersonal itinerary draft. No stays, transport or bookings reserved.\n`;
    const url = URL.createObjectURL(new Blob([text], { type: "text/plain;charset=utf-8" }));
    const link = document.createElement("a"); link.href = url; link.download = "niwadu-itinerary.txt"; link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
  return <>
    <div className="planner-storage"><p>Draft saved only in this browser, on this device. It is not linked to an account or sent to Niwadu.</p><p role="status">{draft.error ? "Not saved in this browser" : saved ? "Saved in this browser" : "Your changes will save here automatically"}</p></div>
    {draft.error && <p className="planner-error" role="alert">{draft.error}</p>}
    <div className="visually-hidden" role="status">{message}</div>
    <div className="planner-grid">
      <section className="planner-editor" aria-label="Edit itinerary">
        <ol className="planner-steps" aria-label="Planning steps">{["Where", "Order & nights", "Review"].map((label, i) => <li key={label}><button disabled={i > 0 && !stops.length} aria-current={step === i ? "step" : undefined} onClick={() => go(i)}><span>0{i + 1}</span>{label}</button></li>)}</ol>
        <h2 ref={heading} tabIndex={-1}>{titles[step]}</h2>
        {step === 0 ? <>
          <p className="planner-help">Choose your stops. You can change their order next. Add up to {LIMIT} destinations.</p>
          {stops.length > 0 && <ol className="planner-chips" aria-label="Selected destinations">{stops.map((stop, i) => <li key={stop.slug}><span>{i + 1}. {bySlug.get(stop.slug)!.name}</span><button onClick={() => remove(i)} aria-label={`Remove ${bySlug.get(stop.slug)!.name}`}>×</button></li>)}</ol>}
          <label className="planner-search">Search destinations<input ref={search} type="search" value={query} onChange={event => setQuery(event.target.value)} placeholder="Search a town, beach or park" autoComplete="off" /></label>
          {stops.length === LIMIT && <p className="planner-help">Your itinerary has {LIMIT} stops. Remove a stop to add another.</p>}
          {visible.length ? <ul className="planner-destinations">{visible.map(destination => <li key={destination.slug}><button disabled={stops.length >= LIMIT} onClick={() => add(destination.slug)} aria-label={`Add ${destination.name}`}><span>{destination.name}</span><span aria-hidden="true">＋</span></button></li>)}</ul> : <div className="planner-empty"><p>No destinations match your search.</p><button className="planner-secondary" onClick={() => { setQuery(""); search.current?.focus(); }}>Clear search</button></div>}
          {!query.trim() && <p className="planner-help">Showing {visible.length} of {available.length} remaining destinations. Search to find a place.</p>}
          <div className="planner-actions"><button className="primary" disabled={!stops.length} onClick={() => go(1)}>Set order and nights</button></div>
        </> : !stops.length ? <div className="planner-empty"><p>Your itinerary is empty.</p><button className="primary" onClick={() => go(0)}>Choose destinations</button></div> : step === 1 ? <>
          <p className="planner-help">Arrange your stops and choose 1–14 nights at each destination.</p>
          <ol ref={stopList} className="planner-stops">{stops.map((stop, i) => { const name = bySlug.get(stop.slug)!.name; return <li key={stop.slug}>
            <div className="planner-stop-name"><span className="planner-number">{i + 1}</span><h3>{name}</h3></div>
            <div className="planner-night-control"><button disabled={stop.nights <= 1} aria-label={`Remove a night in ${name}`} onClick={() => nights(i, -1)}>−</button><span>{stop.nights} {stop.nights === 1 ? "night" : "nights"}</span><button disabled={stop.nights >= 14} aria-label={`Add a night in ${name}`} onClick={() => nights(i, 1)}>+</button></div>
            <div className="planner-stop-actions"><button data-earlier disabled={i === 0} onClick={() => move(i, -1)} aria-label={`Move ${name} earlier`}>↑ Earlier</button><button data-later disabled={i === stops.length - 1} onClick={() => move(i, 1)} aria-label={`Move ${name} later`}>↓ Later</button><button data-remove onClick={() => remove(i)} aria-label={`Remove ${name}`}>Remove</button></div>
          </li>; })}</ol>
          <div className="planner-actions"><button className="planner-secondary" onClick={() => go(0)}>Add destinations</button><button className="primary" onClick={() => go(2)}>Review itinerary</button></div>
        </> : <div className="planner-review">
          <p className="planner-help">{stops.length} stops · {total} {total === 1 ? "night" : "nights"}. Your draft is ready to keep and edit.</p>
          <ol>{stops.map((stop, i) => <li key={stop.slug}><span className="planner-number">{i + 1}</span><strong>{bySlug.get(stop.slug)!.name}</strong><span>{stop.nights} {stop.nights === 1 ? "night" : "nights"}</span></li>)}</ol>
          <p className="planner-help">This itinerary does not reserve stays or transport. Booking and online payment are not available here yet.</p>
          <div className="planner-actions"><button className="planner-secondary" onClick={() => go(1)}>Edit order and nights</button><button className="primary" onClick={download}>Download itinerary</button></div>
        </div>}
      </section>
      <aside className="planner-summary" aria-label="Itinerary summary">
        <div className="planner-map"><SriLankaMap pins={points.map((point, i) => ({ ...point, label: `${i + 1}. ${point.name}` }))} route={points} /></div>
        <div className="planner-summary-card"><h2>Your trip so far</h2><dl><div><dt>Stops</dt><dd>{stops.length}</dd></div><div><dt>Nights</dt><dd>{total}</dd></div></dl>
          {stops.length ? <ol>{stops.map(stop => <li key={stop.slug}>{bySlug.get(stop.slug)!.name}<span>{stop.nights}n</span></li>)}</ol> : <p>Add your first destination to start planning.</p>}
          <p className="planner-help">Map lines show stop order, not road directions or travel times.</p>
          {stops.length > 0 && (confirmClear ? <div className="planner-clear"><p>Clear this browser’s saved itinerary?</p><button className="planner-secondary" onClick={() => setConfirmClear(false)}>Keep itinerary</button><button className="planner-secondary" onClick={() => { update([], "Itinerary cleared."); setConfirmClear(false); go(0); }}>Clear itinerary</button></div> : <button className="planner-secondary" onClick={() => setConfirmClear(true)}>Start over</button>)}
        </div>
      </aside>
    </div>
  </>;
}
