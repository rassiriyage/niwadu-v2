import { FreshLink } from "../../fresh-link";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { discoveryRead, type PublicHotel, type DiscoveryOptions } from "../discovery";

export const dynamic = "force-dynamic";
const readHotel = cache((slug: string) => discoveryRead<{ data: PublicHotel }>(`hotels/${encodeURIComponent(slug)}`));
export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const { data } = await readHotel(slug);
  return { title: data ? `${data.data.name} | Niwadu` : "Stay unavailable | Niwadu", description: data?.data.description.slice(0, 160), robots: { index: false, follow: false }, alternates: { canonical: `https://niwadu.com/hotels/${encodeURIComponent(slug)}` } };
}
export default async function HotelPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const [reply, optionsReply] = await Promise.all([readHotel(slug), discoveryRead<{ data: DiscoveryOptions }>("discovery-options")]);
  if (reply.status === 404) notFound();
  const hotel = reply.data?.data;
  if (!hotel) return <section className="discovery-state" role="alert"><h1>This stay could not be loaded</h1><p>Please try again. No availability or booking has been confirmed.</p><FreshLink className="discovery-button" href={`/hotels/${encodeURIComponent(slug)}`}>Try again</FreshLink><FreshLink className="discovery-button" href="/hotels?sort=name">Browse stays</FreshLink></section>;
  const label = optionsReply.data?.data.property_types.find(type => type.key === hotel.property_type)?.label || hotel.property_type.replaceAll("_", " ");
  return <article className="discovery-detail"><FreshLink className="discovery-back" href="/hotels?sort=name">← All stays</FreshLink><h1>{hotel.name}</h1><p className="discovery-lede">{hotel.city}, {hotel.country} · {label}</p>
    <div className="discovery-photo discovery-detail-photo">Photo not available</div>
    <div className="discovery-detail-grid"><section><h2>About this stay</h2><p className="discovery-description">{hotel.description}</p><dl><dt>Property type</dt><dd>{label}</dd><dt>Location</dt><dd>{hotel.city}, {hotel.country}</dd></dl></section>
      <aside className="discovery-state"><h2>Plan your visit</h2><p>Booking and online payment are not available yet. Prices, room availability and policies have not been confirmed.</p><FreshLink className="primary" href="/plan">Plan a trip</FreshLink></aside>
    </div>
  </article>;
}
