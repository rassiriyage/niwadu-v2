export type PublicHotel = {
  id: number; slug: string; name: string; description: string;
  property_type: string; city: string; country: string; photo: null;
};
export type DiscoveryOptions = { property_types: { key: string; label: string }[]; capabilities: { filters: string[]; sorts: string[]; availability_search: false; price_sort: false } };
export type DiscoveryResult = { data: PublicHotel[]; meta: { current_page: number; last_page: number; total: number; per_page: number } };
export type SearchValues = Record<string, string | string[] | undefined>;
export type Query = { q: string; types: string[]; sort: string; page: number };

export function readQuery(values: SearchValues): { query: Query; problems: string[] } {
  const problems: string[] = [];
  for (const [key, value] of Object.entries(values)) {
    if (key.startsWith("utm_") || key === "gclid") continue;
    if (!["q", "sort", "page", "property_types[]"].includes(key)) problems.push(`The “${key}” filter is not available in this search.`);
    else if (key !== "property_types[]" && Array.isArray(value)) problems.push(`The “${key}” filter was provided more than once.`);
  }
  const scalar = (key: string) => typeof values[key] === "string" ? values[key] as string : "";
  const q = scalar("q").trim();
  const sort = values.sort === undefined ? "name" : scalar("sort");
  const rawPage = scalar("page");
  const page = rawPage ? Number(rawPage) : 1;
  const rawTypes = values["property_types[]"];
  const types = [...new Set(Array.isArray(rawTypes) ? rawTypes : rawTypes ? [rawTypes] : [])].sort();
  if (q.length > 100) problems.push("Search property names using 100 characters or fewer.");
  if (sort !== "name") problems.push("This sort is not available. Choose Name A–Z to continue.");
  if ((values.page !== undefined && !/^[1-9]\d*$/.test(rawPage)) || !Number.isInteger(page) || page < 1 || page > 100000) problems.push("Page must be a whole number from 1 to 100000.");
  if ((Array.isArray(rawTypes) && rawTypes.length > 6) || types.length > 6 || types.some(type => !/^[a-z_]{1,30}$/.test(type))) problems.push("The selected property types are invalid.");
  return { query: { q, sort, page, types }, problems };
}
export function searchURL(query: Query, patch: Partial<Query> = {}) {
  const next = { ...query, ...patch };
  const params = new URLSearchParams();
  if (next.q) params.set("q", next.q);
  for (const type of [...new Set(next.types)].sort()) params.append("property_types[]", type);
  params.set("sort", next.sort);
  if (next.page !== 1) params.set("page", String(next.page));
  return `/hotels?${params}`;
}
export async function discoveryRead<T>(path: string): Promise<{ data: T | null; status: number }> {
  try {
    const origin = process.env.API_ORIGIN || "http://127.0.0.1:8000";
    const response = await fetch(`${origin}/api/v1/public/${path}`, {
      cache: "no-store", headers: { Accept: "application/json" }, signal: AbortSignal.timeout(8000),
    });
    if (!response.ok) return { data: null, status: response.status };
    return { data: await response.json() as T, status: response.status };
  } catch { return { data: null, status: 503 }; }
}
