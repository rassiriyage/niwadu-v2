"use client";
import { useEffect, useRef } from "react";
import { searchURL, type DiscoveryOptions, type Query, type Option } from "./discovery";

export function DiscoveryFilters({ options, query }: { options: DiscoveryOptions; query: Query }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const form = useRef<HTMLFormElement>(null);
  const choices = (options: Option[], selected: string[]) => [...options, ...selected.filter(key => !options.some(option => option.key === key)).map(key => ({ key, label: `${key.replaceAll("_", " ")} (no current listings)` }))];
  const count = query.types.length + query.themes.length + query.amenities.length + [query.destination, query.district, query.region].filter(Boolean).length;
  const groups = [
    { key: "types", name: "property_types[]", title: "Property type", options: options.property_types, help: "Match any selected type." },
    { key: "themes", name: "themes[]", title: "Experience themes", options: options.themes, help: "Match any selected theme." },
    { key: "amenities", name: "amenities[]", title: "Amenities", options: options.amenities, help: "Match every selected amenity." },
  ] as const;
  const locations = [{ key: "destination", title: "Destination", options: options.destinations }, { key: "district", title: "District", options: options.districts }, { key: "region", title: "Region", options: options.regions }] as const;
  return <>
    <button className="discovery-button" onClick={() => { form.current?.reset(); dialog.current?.showModal(); }}>Filters{count ? ` (${count})` : ""}</button>
    <dialog className="discovery-dialog" ref={dialog} aria-labelledby="filter-title">
      <form ref={form} action="/hotels" method="get">
        <div className="discovery-dialog-heading"><h2 id="filter-title">Filter stays</h2><button type="button" aria-label="Close filters" onClick={() => dialog.current?.close()}>×</button></div>
        <input type="hidden" name="sort" value={query.sort} />
        {query.q && <input type="hidden" name="q" value={query.q} />}
        {locations.map(group => (group.options || query[group.key]) && <fieldset key={group.key}><legend>{group.title}</legend><select aria-label={group.title} name={group.key} defaultValue={query[group.key]}><option value="">Any {group.title.toLowerCase()}</option>{choices(group.options || [], query[group.key] ? [query[group.key]] : []).map(option => <option key={option.key} value={option.key}>{option.label}</option>)}</select></fieldset>)}
        {groups.map(group => (group.options || query[group.key].length > 0) && <fieldset key={group.key}><legend>{group.title}</legend><p>{group.help}</p>
          {choices(group.options || [], query[group.key]).map(option => <label key={option.key}><input type="checkbox" name={group.name} value={option.key} defaultChecked={query[group.key].includes(option.key)} />{option.label}</label>)}
        </fieldset>)}
        <p className="discovery-muted">Dates, prices, star ratings and guest availability are not filters in this browse-only catalogue.</p>
        <div className="discovery-actions"><button className="discovery-button" type="button" onClick={() => dialog.current?.close()}>Cancel</button><button className="primary" type="submit">Apply filters</button></div>
      </form>
    </dialog>
  </>;
}

export function QueryFields({ query, omit }: { query: Query; omit: string[] }) {
  return [...new URLSearchParams(searchURL(query).split("?")[1])].filter(([key]) => key !== "page" && !omit.includes(key)).map(([key, value], i) => <input key={`${key}-${i}`} type="hidden" name={key} value={value} />);
}

export function DiscoverySort({ query, options }: { query: Query; options: DiscoveryOptions }) {
  const editorial = options.capabilities.sorts.includes("editorial");
  return <form action="/hotels" method="get" className="discovery-sort"><QueryFields query={query} omit={["sort"]} /><label>Sort<select name="sort" defaultValue={query.sort}><option value="name">Name A–Z</option>{(editorial || query.sort === "editorial") && <option value="editorial">Recommended by Niwadu{editorial ? "" : " (unavailable)"}</option>}</select></label><button className="discovery-button">Apply sort</button></form>;
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
