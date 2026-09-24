import { FreshLink } from "../fresh-link";
import type { Metadata } from "next";
import { DiscoveryFilters, DiscoverySort, QueryFields } from "./filters";
import { discoveryRead, readQuery, searchURL, type DiscoveryOptions, type DiscoveryResult, type SearchValues } from "./discovery";

export const dynamic = "force-dynamic";
export const metadata: Metadata = { title: "All stays | Niwadu", description: "Browse reviewed property information and filter stays.", robots: { index: false, follow: false }, alternates: { canonical: "https://niwadu.com/hotels" } };
export default async function HotelsPage({ searchParams }: { searchParams: Promise<SearchValues> }) {
  const raw = await searchParams;
  const { query, problems } = readQuery(raw);
  const [optionsReply, resultReply] = await Promise.all([
    discoveryRead<{ data: DiscoveryOptions }>("discovery-options"),
    problems.length ? Promise.resolve({ data: null, status: 422 }) : discoveryRead<DiscoveryResult>(`hotels?${searchURL(query).split("?")[1]}`),
  ]);
  const options = optionsReply.data?.data;
  const result = resultReply.data;
  if (resultReply.status === 422 && !problems.length) problems.push("These filters are not available. Change or remove them to continue.");
  const ready = options && Array.isArray(options.property_types) && result && Array.isArray(result.data) && result.meta;
  return <>
    <FreshLink className="discovery-back" href="/">← Back to explore</FreshLink>
    <h1>All stays</h1>
    <p className="discovery-lede">Browse reviewed property information. Prices and availability are not confirmed here.</p>
    <form className="discovery-search" action="/hotels" method="get"><label>Search stays<input type="search" name="q" defaultValue={query.q} maxLength={100} placeholder={options?.destinations ? "Property or destination name" : "Property name"} /></label><QueryFields query={query} omit={["q"]} /><button className="primary">Search</button></form>
    <div className="discovery-toolbar">{options && <DiscoveryFilters options={options} query={query} />}{options && <DiscoverySort query={query} options={options} />}</div>
    {(query.q || query.types.length || query.themes.length || query.amenities.length || query.destination || query.district || query.region) && <div className="discovery-chips" aria-label="Applied filters">
      {query.q && <FreshLink href={searchURL(query, { q: "", page: 1 })}>Search: {query.q} <span aria-label="Remove search filter">×</span></FreshLink>}
      {([{ key: "types", options: options?.property_types }, { key: "themes", options: options?.themes }, { key: "amenities", options: options?.amenities }] as const).flatMap(group => query[group.key].map(value => <FreshLink key={`${group.key}-${value}`} href={searchURL(query, { [group.key]: query[group.key].filter(item => item !== value), page: 1 })}>{group.options?.find(option => option.key === value)?.label || value} <span aria-label={`Remove ${value} filter`}>×</span></FreshLink>))}
      {([{ key: "destination", options: options?.destinations }, { key: "district", options: options?.districts }, { key: "region", options: options?.regions }] as const).map(group => query[group.key] && <FreshLink key={group.key} href={searchURL(query, { [group.key]: "", page: 1 })}>{group.options?.find(option => option.key === query[group.key])?.label || query[group.key]} <span aria-label={`Remove ${group.key} filter`}>×</span></FreshLink>)}
      <FreshLink href="/hotels?sort=name">Clear all filters</FreshLink>
    </div>}
    {problems.length ? <section className="discovery-state" role="alert"><h2>Some search choices are unavailable</h2><ul>{problems.map((problem, i) => <li key={i}>{problem}</li>)}</ul><p>Your requested search has not been broadened. Review these choices or start a new search.</p><FreshLink className="discovery-button" href="/hotels?sort=name">Start a new search</FreshLink></section> : !ready ? <section className="discovery-state" role="alert"><h2>Stays could not be loaded</h2><p>The catalogue is temporarily unavailable. Your search is still here.</p><FreshLink className="discovery-button" href={searchURL(query)}>Try again</FreshLink><FreshLink className="discovery-button" href="/plan">Plan your itinerary</FreshLink></section> : <>
      <p className="discovery-count">{result.meta.total} {result.meta.total === 1 ? "stay" : "stays"}</p>
      {result.data.length ? <ul className="discovery-grid">{result.data.map(hotel => <li key={hotel.id}><FreshLink className="discovery-card" href={`/hotels/${encodeURIComponent(hotel.slug)}`}><div className="discovery-photo">Photo not available</div><h2>{hotel.name}</h2><p>{hotel.destination?.name || hotel.city}, {hotel.country}</p>{hotel.district && <p>{hotel.district.label}</p>}<span className="discovery-type">{options.property_types.find(type => type.key === hotel.property_type)?.label || hotel.property_type}</span><p className="discovery-muted">Booking not available yet</p></FreshLink></li>)}</ul> : <section className="discovery-state"><h2>{query.page > result.meta.last_page ? "No stays on this page" : "No stays match your search"}</h2><p>{query.page > result.meta.last_page ? "Return to the first page with your filters." : "Try another name or remove a filter. New listings appear after review."}</p><FreshLink className="discovery-button" href={query.page > result.meta.last_page ? searchURL(query, { page: 1 }) : "/hotels?sort=name"}>{query.page > result.meta.last_page ? "Return to first page" : "Clear all filters"}</FreshLink></section>}
      {result.meta.last_page > 1 && <nav className="discovery-pagination" aria-label="Results pages">{query.page > 1 && <FreshLink className="discovery-button" href={searchURL(query, { page: query.page - 1 })}>Previous page</FreshLink>}<span>Page {query.page} of {result.meta.last_page}</span>{query.page < result.meta.last_page && <FreshLink className="discovery-button" href={searchURL(query, { page: query.page + 1 })}>Next page</FreshLink>}</nav>}
    </>}
  </>;
}
