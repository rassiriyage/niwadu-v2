import { FreshLink } from "../fresh-link";
import type { Metadata } from "next";
import { DiscoveryFilters } from "./filters";
import { discoveryRead, readQuery, searchURL, type DiscoveryOptions, type DiscoveryResult, type SearchValues } from "./discovery";

export const dynamic = "force-dynamic";
export const metadata: Metadata = { title: "All stays | Niwadu", description: "Browse reviewed property information and filter by property type.", robots: { index: false, follow: false }, alternates: { canonical: "https://niwadu.com/hotels" } };
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
    <form className="discovery-search" action="/hotels" method="get"><label>Search property names<input type="search" name="q" defaultValue={query.q} maxLength={100} placeholder="Property name" /></label><input type="hidden" name="sort" value="name" />{query.types.map(type => <input key={type} type="hidden" name="property_types[]" value={type} />)}<button className="primary">Search</button></form>
    <div className="discovery-toolbar">{options && <DiscoveryFilters options={options} query={query} />}<p>Sort: <strong>Name A–Z</strong></p></div>
    {(query.q || query.types.length > 0) && <div className="discovery-chips" aria-label="Applied filters">{query.q && <FreshLink href={searchURL(query, { q: "", page: 1 })}>Name: {query.q} <span aria-label="Remove name filter">×</span></FreshLink>}{query.types.map(type => <FreshLink key={type} href={searchURL(query, { types: query.types.filter(value => value !== type), page: 1 })}>{options?.property_types.find(option => option.key === type)?.label || type} <span aria-label={`Remove ${type} filter`}>×</span></FreshLink>)}<FreshLink href="/hotels?sort=name">Clear all filters</FreshLink></div>}
    {problems.length ? <section className="discovery-state" role="alert"><h2>Some search choices are unavailable</h2><ul>{problems.map((problem, i) => <li key={i}>{problem}</li>)}</ul><p>Your requested search has not been broadened. Review these choices or start a new search.</p><FreshLink className="discovery-button" href="/hotels?sort=name">Start a new search</FreshLink></section> : !ready ? <section className="discovery-state" role="alert"><h2>Stays could not be loaded</h2><p>The catalogue is temporarily unavailable. Your search is still here.</p><FreshLink className="discovery-button" href={searchURL(query)}>Try again</FreshLink><FreshLink className="discovery-button" href="/plan">Plan your itinerary</FreshLink></section> : <>
      <p className="discovery-count">{result.meta.total} {result.meta.total === 1 ? "stay" : "stays"}</p>
      {result.data.length ? <ul className="discovery-grid">{result.data.map(hotel => <li key={hotel.id}><FreshLink className="discovery-card" href={`/hotels/${encodeURIComponent(hotel.slug)}`}><div className="discovery-photo">Photo not available</div><h2>{hotel.name}</h2><p>{hotel.city}, {hotel.country}</p><span className="discovery-type">{options.property_types.find(type => type.key === hotel.property_type)?.label || hotel.property_type}</span><p className="discovery-muted">Booking not available yet</p></FreshLink></li>)}</ul> : <section className="discovery-state"><h2>{query.page > result.meta.last_page ? "No stays on this page" : "No stays match your search"}</h2><p>{query.page > result.meta.last_page ? "Return to the first page with your filters." : "Try another name or remove a filter. New listings appear after review."}</p><FreshLink className="discovery-button" href={query.page > result.meta.last_page ? searchURL(query, { page: 1 }) : "/hotels?sort=name"}>{query.page > result.meta.last_page ? "Return to first page" : "Clear all filters"}</FreshLink></section>}
      {result.meta.last_page > 1 && <nav className="discovery-pagination" aria-label="Results pages">{query.page > 1 && <FreshLink className="discovery-button" href={searchURL(query, { page: query.page - 1 })}>Previous page</FreshLink>}<span>Page {query.page} of {result.meta.last_page}</span>{query.page < result.meta.last_page && <FreshLink className="discovery-button" href={searchURL(query, { page: query.page + 1 })}>Next page</FreshLink>}</nav>}
    </>}
  </>;
}
