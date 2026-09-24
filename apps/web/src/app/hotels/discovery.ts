export type PublicHotel = {
  id: number; slug: string; name: string; description: string;
  property_type: string; city: string; country: string; photo: null;
  destination?: { id: number; slug: string; name: string } | null; district?: { key: string; label: string } | null; themes?: string[]; amenities?: string[]; star_classification?: null;
};
export type Option = { key: string; label: string };
export type DiscoveryOptions = { property_types: Option[]; destinations?: Option[]; districts?: Option[]; regions?: Option[]; themes?: Option[]; amenities?: Option[]; capabilities: { filters: string[]; sorts: string[]; availability_search: false; price_sort: false } };
export type DiscoveryResult = { data: PublicHotel[]; meta: { current_page: number; last_page: number; total: number; per_page: number } };
export type SearchValues = Record<string, string | string[] | undefined>;
export type Query = { q: string; types: string[]; sort: string; page: number; destination: string; district: string; region: string; themes: string[]; amenities: string[] };

export function readQuery(input: SearchValues): { query: Query; problems: string[] } {
  const values = { ...input };
  const problems: string[] = [];
  if (values.dest !== undefined) {
    if (values.destination !== undefined) problems.push("Destination was provided more than once.");
    else values.destination = values.dest;
    delete values.dest;
  }
  if (values.category !== undefined) {
    const category = values.category;
    if (typeof category !== "string" || !["beach", "hills", "wild", "culture", "adventure", "city", "north"].includes(category)) problems.push("This browse category is unavailable.");
    else if (category === "north") {
      if (values.region !== undefined && values.region !== "north-east") problems.push("Conflicting region choices.");
      else values.region = "north-east";
    } else {
      const themes = values["themes[]"];
      values["themes[]"] = [...(Array.isArray(themes) ? themes : themes ? [themes] : []), category];
    }
    delete values.category;
  }
  if (values.sort === "popular") values.sort = "editorial";
  const arrays = ["property_types[]", "themes[]", "amenities[]"];
  for (const [key, value] of Object.entries(values)) {
    if (key.startsWith("utm_") || key === "gclid") continue;
    if (!["q", "sort", "page", "destination", "district", "region", ...arrays].includes(key)) problems.push(`The “${key}” filter is not available in this search.`);
    else if (!arrays.includes(key) && Array.isArray(value)) problems.push(`The “${key}” filter was provided more than once.`);
  }
  const scalar = (key: string) => typeof values[key] === "string" ? values[key] as string : "";
  const list = (key: string, limit: number) => {
    const raw = values[key];
    const entries = Array.isArray(raw) ? raw : raw ? [raw] : [];
    if (entries.length > limit || entries.some(item => !/^[a-z_]{1,30}$/.test(item))) problems.push(`The selected ${key.replace("[]", "").replaceAll("_", " ")} are invalid.`);
    return [...new Set(entries)].sort();
  };
  const q = scalar("q").trim();
  const sort = values.sort === undefined ? "name" : scalar("sort");
  const rawPage = scalar("page");
  const page = rawPage ? Number(rawPage) : 1;
  const destination = scalar("destination"), district = scalar("district"), region = scalar("region");
  if (q.length > 100) problems.push("Search using 100 characters or fewer.");
  if (!["name", "editorial"].includes(sort)) problems.push("This sort is not available. Choose Name A–Z to continue.");
  if ((values.page !== undefined && !/^[1-9]\d*$/.test(rawPage)) || !Number.isInteger(page) || page < 1 || page > 100000) problems.push("Page must be a whole number from 1 to 100000.");
  for (const [key, value] of Object.entries({ destination, district, region })) if (value && !/^[a-z0-9_-]{1,100}$/.test(value)) problems.push(`The ${key} is invalid.`);
  return { query: { q, sort, page, types: list("property_types[]", 6), themes: list("themes[]", 6), amenities: list("amenities[]", 8), destination, district, region }, problems };
}
export function searchURL(query: Query, patch: Partial<Query> = {}) {
  const next = { ...query, ...patch };
  const params = new URLSearchParams();
  if (next.q) params.set("q", next.q);
  for (const type of [...new Set(next.types)].sort()) params.append("property_types[]", type);
  for (const key of ["destination", "district", "region"] as const) if (next[key]) params.set(key, next[key]);
  for (const key of ["themes", "amenities"] as const) for (const value of [...new Set(next[key])].sort()) params.append(`${key}[]`, value);
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
