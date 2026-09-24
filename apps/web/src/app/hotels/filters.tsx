"use client";
import { useEffect, useRef } from "react";
import type { DiscoveryOptions, Query } from "./discovery";

export function DiscoveryFilters({ options, query }: { options: DiscoveryOptions; query: Query }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const form = useRef<HTMLFormElement>(null);
  const types = [...options.property_types, ...query.types.filter(key => !options.property_types.some(type => type.key === key)).map(key => ({ key, label: `${key.replaceAll("_", " ")} (no current listings)` }))];
  return <>
    <button className="discovery-button" onClick={() => { form.current?.reset(); dialog.current?.showModal(); }}>Filters{query.types.length ? ` (${query.types.length})` : ""}</button>
    <dialog className="discovery-dialog" ref={dialog} aria-labelledby="filter-title">
      <form ref={form} action="/hotels" method="get">
        <div className="discovery-dialog-heading"><h2 id="filter-title">Filter stays</h2><button type="button" aria-label="Close filters" onClick={() => dialog.current?.close()}>×</button></div>
        <input type="hidden" name="sort" value="name" />
        {query.q && <input type="hidden" name="q" value={query.q} />}
        <fieldset><legend>Property type</legend><p>Match any selected type.</p>
          {types.length ? types.map(type => <label key={type.key}><input type="checkbox" name="property_types[]" value={type.key} defaultChecked={query.types.includes(type.key)} />{type.label}</label>) : <p>No property types are available yet.</p>}
        </fieldset>
        <p className="discovery-muted">Dates, prices, star ratings and guest availability are not filters in this browse-only catalogue.</p>
        <div className="discovery-actions"><button className="discovery-button" type="button" onClick={() => dialog.current?.close()}>Cancel</button><button className="primary" type="submit">Apply filters</button></div>
      </form>
    </dialog>
  </>;
}

// Native discovery links avoid the client router cache. Browser history restores must reread the origin.
export function RefreshDiscoveryOnReturn() {
  useEffect(() => {
    const restore = (event: PageTransitionEvent) => { if (event.persisted) window.location.reload(); };
    window.addEventListener("pageshow", restore);
    return () => window.removeEventListener("pageshow", restore);
  }, []);
  return null;
}
