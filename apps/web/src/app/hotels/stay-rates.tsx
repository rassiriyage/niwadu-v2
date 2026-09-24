import { discoveryRead, type SearchValues } from "./discovery";
import { FreshLink } from "../fresh-link";

type Rate = { rate_plan_id: number; name: string; room_name: string; max_adults: number; currency: "LKR"; total_minor: number; policy: { version: string; text: string }; expires_at: string };
type Rates = { data: Rate[]; meta: { inventory_reserved: false } };

export async function StayRates({ slug, values }: { slug: string; values: SearchValues }) {
  const path = `/hotels/${encodeURIComponent(slug)}`;
  const scalar = (key: string) => typeof values[key] === "string" ? values[key] as string : "";
  const arrival = scalar("arrival"), departure = scalar("departure"), adults = scalar("adults");
  const entries = Object.entries(values).filter(([key]) => !key.startsWith("utm_") && key !== "gclid");
  const requested = entries.length > 0;
  const invalid = entries.some(([key, value]) => !["arrival", "departure", "adults"].includes(key) || Array.isArray(value));
  const query = new URLSearchParams({ arrival, departure, adults });
  const reply = requested && !invalid ? await discoveryRead<Rates>(`hotels/${encodeURIComponent(slug)}/rate-plans?${query}`) : null;
  const problem = invalid || reply?.status === 422 ? "Check your dates and guests. Choose a stay of 1–30 nights and 1–30 adults; additional or repeated search fields are not supported."
    : reply && (!reply.data || reply.data.meta?.inventory_reserved !== false) ? "Room rates could not be loaded. Please try again." : null;
  return <section className="discovery-state stay-rates" aria-labelledby="stay-rates-heading">
    <h2 id="stay-rates-heading">Check room rates</h2>
    <p>Choose dates for one room, adults only. Booking and online payment are not available yet.</p>
    <form action={path} method="get" className="discovery-search stay-dates">
      <label>Arrival<input type="date" name="arrival" required defaultValue={arrival} /></label>
      <label>Departure<input type="date" name="departure" required defaultValue={departure} /></label>
      <label>Adults<input type="number" name="adults" min={1} max={30} required defaultValue={adults || "2"} /></label>
      <button className="primary">Check rates</button>
    </form>
    {problem && <p role="alert">{problem}</p>}
    {reply?.data && !problem && <>
      <p>Rates for {arrival} to {departure} · {adults} {adults === "1" ? "adult" : "adults"} · one room. Nothing is reserved. Recheck rates before continuing.</p>
      {reply.data.data.length === 0 ? <p>No room rates are available for these dates and guests. Try different dates.</p> : <ul className="stay-rate-list">{reply.data.data.map(rate => <li key={rate.rate_plan_id}>
        <h3>{rate.room_name} · {rate.name}</h3>
        <p>{new Intl.NumberFormat("en-LK", { style: "currency", currency: "LKR" }).format(rate.total_minor / 100)} total for the stay, including mandatory taxes and fees</p>
        <p>Up to {rate.max_adults} adults</p><p className="stay-policy">{rate.policy.text}</p>
        <p>Rate check expires at <time dateTime={rate.expires_at}>{rate.expires_at}</time>. Availability and price can change.</p>
      </li>)}</ul>}
    </>}
    {requested && <FreshLink className="discovery-button" href={path}>Clear dates and guests</FreshLink>}
  </section>;
}
