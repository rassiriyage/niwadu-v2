import { notFound } from "next/navigation";
import { PlanningPanel, type Selection } from "../planning-panel";
import { discoveryRead, type PublicHotel, type SearchValues } from "../../../hotels/discovery";
export default async function ReviewPage({ searchParams }: { searchParams: Promise<SearchValues> }) {
  if (process.env.BOOKING_PLANNING_ENABLED !== "true") notFound();
  const raw = await searchParams;
  const keys = ["hotel_slug", "hotel_id", "rate_plan_id", "arrival", "departure", "adults"];
  if (Object.keys(raw).some(key => !keys.includes(key)) || keys.some(key => typeof raw[key] !== "string")) notFound();
  const selection: Selection = { hotel_id: Number(raw.hotel_id), rate_plan_id: Number(raw.rate_plan_id), arrival: raw.arrival as string, departure: raw.departure as string, adults: Number(raw.adults) };
  if (![selection.hotel_id, selection.rate_plan_id, selection.adults].every(value => Number.isSafeInteger(value) && value > 0) || selection.adults > 30 || ![selection.arrival, selection.departure].every(value => /^\d{4}-\d{2}-\d{2}$/.test(value))) notFound();
  const hotel = await discoveryRead<{ data: PublicHotel }>(`hotels/${encodeURIComponent(raw.hotel_slug as string)}`);
  if (!hotel.data || hotel.data.data.id !== selection.hotel_id) return <><h1>Stay selection unavailable</h1><p>This property could not be verified. Your dates remain in this page’s URL. Reload to try again.</p></>;
  return <><h1>Review stay plan</h1><PlanningPanel selection={selection} hotelName={hotel.data.data.name} /></>;
}
