import type { Metadata } from "next";
import { CardImage } from "./card-image";
import Link from "next/link";
import content from "./homepage-content.json";
import { Carousel, PreviewNavigation, PreviewAction, ReferenceHeader } from "./homepage-controls";
import { SriLankaMap } from "./SriLankaMap";
import "./public.css";

export const metadata: Metadata = {
  title: "Niwadu — stays and experiences across Sri Lanka",
  description: "Explore Sri Lankan stays and experiences in the Niwadu preview.",
  alternates: { canonical: "https://niwadu.com/" },
  robots: { index: false, follow: false },
};
const footer = [
  { title: "Support", links: ["Help Centre", "Contact us", "Cancellation options", "Booking terms"] },
  { title: "Explore", links: ["All stays", "Destinations", "Experiences", "Wishlists"] },
  { title: "Niwadu", links: ["About", "Trip planner", "Cover Sri Lanka", "Facebook", "Instagram"] },
];
function Row({ section, index }: { section: typeof content[number]; index: number }) {
  return <Carousel id={`collection-${index}`} title={section.title} kind={section.kind}>
    {section.cards.map((card, cardIndex) => <li key={card.href}>
      <div className="card-wrap">
        <PreviewAction label={card.title} className="listing-card">
          <span className="card-image"><CardImage src={card.image} width={card.imageWidth} height={card.imageHeight} priority={index === 0 && cardIndex === 0} />{index < 3 && <span className="featured">Featured</span>}</span>
          <span className="card-copy"><span className="card-name">{card.title}</span><span className="card-location">{card.detail}</span><span className="card-rate">{section.kind === "hotel" ? "Rates unavailable" : "Explore in preview"}</span></span>
        </PreviewAction>
        {section.kind === "hotel" && <PreviewAction className="wishlist" accessibleName={`Save ${card.title} to wishlist`} label={`Save ${card.title} to wishlist`}><svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 28S2 20 2 10C2 2 12 1 16 8c4-7 14-6 14 2 0 10-14 18-14 18Z" /></svg></PreviewAction>}
      </div>
    </li>)}
  </Carousel>;
}
export default function Home() {
  return <div className="public-site"><PreviewNavigation>
    <a className="skip-link" href="#public-content">Skip to content</a>
    <ReferenceHeader />
    <main id="public-content" className="public-container">
      <h1 className="visually-hidden">Explore Sri Lanka with Niwadu</h1>
      {content.slice(0, 4).map((section, index) => <Row key={section.title} section={section} index={index} />)}
      <section className="promos" aria-label="Explore Sri Lanka">
        <PreviewAction className="promo" label="Plan a trip">
          <span className="promo-map"><SriLankaMap route={[{ lat: 6.93, lng: 79.85 }, { lat: 7.96, lng: 80.76 }, { lat: 7.29, lng: 80.64 }, { lat: 6.87, lng: 81.05 }, { lat: 5.95, lng: 80.46 }, { lat: 6.93, lng: 79.85 }]} pins={[{ lat: 7.96, lng: 80.76, pill: "Sigiriya" }, { lat: 6.87, lng: 81.05, pill: "Ella" }, { lat: 5.95, lng: 80.46, pill: "Mirissa" }]} /></span>
          <span className="eyebrow">Trip planner</span><span className="promo-title">Plan a route around the island</span><span className="promo-description">Choose your destinations, arrange stops and nights, and save your itinerary in this browser.</span><span className="primary">Start planning</span>
        </PreviewAction>
        <PreviewAction className="promo" label="Cover Sri Lanka">
          <span className="promo-map"><SriLankaMap visited={["galle", "matara", "kandy", "badulla", "colombo", "nuwaraeliya", "hambantota", "trincomalee"]} /></span>
          <span className="eyebrow">Cover Sri Lanka</span><span className="promo-title">How much of Sri Lanka have you covered?</span><span className="promo-description">Explore the district map preview. Your personal travel record is coming next.</span><span className="primary">Check my coverage</span>
        </PreviewAction>
      </section>
      {content.slice(4).map((section, index) => <Row key={section.title} section={section} index={index + 4} />)}
      <div className="show-all"><PreviewAction className="primary" label="All stays">Show all stays</PreviewAction></div>
    </main>
    <footer className="site-footer"><div className="public-container footer-columns">{footer.map(group => <div key={group.title}><h3>{group.title}</h3><ul>{group.links.map(label => <li key={label}><PreviewAction label={label}>{label}</PreviewAction></li>)}</ul></div>)}</div>
      <div className="footer-bottom"><div className="public-container"><p>© {new Date().getFullYear()} Niwadu.com · <PreviewAction label="Privacy">Privacy</PreviewAction> · <PreviewAction label="Terms">Terms</PreviewAction> · <a href="tel:+94760730139">+94 76 073 0139</a> · <a href="mailto:bookings@niwadu.com">Email</a> · <Link href="/">Home</Link></p><span>English (LK) · LKR</span></div></div>
    </footer>
    <PreviewAction className="contact-float" accessibleName="Contact Niwadu" label="Contact Niwadu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a9 9 0 0 1-13.5 7.8L3 21l1.7-4.5A9 9 0 1 1 21 11.5Z" /></svg></PreviewAction>
  </PreviewNavigation></div>;
}
