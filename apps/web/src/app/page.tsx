import type { Metadata } from "next";
import Image from "next/image";
import content from "./homepage-content.json";
import { Carousel, SearchPreview } from "./homepage-controls";
import "./public.css";

export const metadata: Metadata = {
  title: "Discover Sri Lanka with Niwadu.com — Hotels, Destinations, Travel Experiences & Activities",
  description: "Explore hotels, destinations and travel experiences in Sri Lanka with Niwadu.",
  alternates: { canonical: "https://niwadu.com/" },
  // Presentation preview remains unindexable until catalog and migration acceptance.
  robots: { index: false, follow: false },
};

const navigation = [
  { title: "Destinations", href: "https://niwadu.com/destinations", icon: "destinations" },
  { title: "Hotels", href: "https://niwadu.com/hotels", icon: "hotels" },
  { title: "Travel Experiences", href: "https://niwadu.com/packages", icon: "experiences" },
];
const footer = [
  { title: "How to reach us.", links: [["+94 76 073 0139", "tel:+94760730139"], ["bookings@niwadu.com", "mailto:bookings@niwadu.com"]] },
  { title: "What we offer.", links: [["Home", "https://niwadu.com/"], ...navigation.map(n => [n.title, n.href])] },
  { title: "Who we are.", links: [["About", "https://niwadu.com/support/about"], ["FAQ", "https://niwadu.com/support/faq"]] },
  { title: "More for you.", links: [["Terms & Conditions", "https://niwadu.com/support/terms-condition"], ["Privacy Policy", "https://niwadu.com/support/privacy"], ["Refund Policy", "https://niwadu.com/support/refund-policy"]] },
];

export default function Home() {
  return <div className="public-site">
    <a className="skip-link" href="#public-content">Skip to content</a>
    <header className="site-header">
      <div className="header-nav public-container">
        <a className="site-logo" href="https://niwadu.com/" aria-label="Niwadu home"><Image src="/reference/d7ecd7236c7a091a.svg" alt="Niwadu" width={97} height={24} /></a>
        <nav className="desktop-nav" aria-label="Main navigation">{navigation.map(n => <a key={n.href} href={n.href}>{n.title}</a>)}</nav>
        <div className="account-nav"><span className="currency" aria-label="Sri Lankan rupees">LKR <span className="chevron down" aria-hidden="true" /></span><a href="https://niwadu.com/login">Log In</a><a href="https://niwadu.com/register">Sign Up</a></div>
        <SearchPreview />
        <span className="mobile-globe" aria-label="Language: English"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18M5 6h14M5 18h14"/></svg></span>
      </div>
    </header>
    <main id="public-content" className="public-container">
      <h1 className="visually-hidden">Explore Sri Lanka with Niwadu</h1>
      {content.map((section, row) => <Carousel key={section.title} id={`collection-${row}`} title={section.title} href={section.href} kind={section.kind}>
        {section.cards.map((card, index) => <li key={card.href}>
          <a href={card.href} className="listing-card">
            <div className="card-image"><Image src={card.image} alt="" fill quality={90} loading="eager" sizes={section.kind === "hotel" ? "(max-width: 575px) calc(100vw - 72px), (max-width: 767px) 46vw, (max-width: 1023px) 30vw, 16vw" : "(max-width: 575px) calc(100vw - 72px), (max-width: 1023px) 46vw, 33vw"} fetchPriority={row === 0 && index === 0 ? "high" : undefined} /></div>
            <div className="card-copy"><h3>{card.title}</h3><p>{section.kind === "hotel" ? "View current rates" : card.detail}</p>{section.kind === "destination" && <span className="explore">Expore Now <span className="chevron" aria-hidden="true" /></span>}</div>
          </a>
        </li>)}
      </Carousel>)}
    </main>
    <footer className="site-footer">
      <div className="public-container footer-content">
        <Image className="footer-logo" src="/reference/e7490febebfd4774.svg" alt="Niwadu" width={162} height={40} />
        <div className="footer-columns">{footer.map(group => <div key={group.title}><p>{group.title}</p><ul>{group.links.map(([label, href]) => <li key={href}><a href={href}>{label}</a></li>)}</ul></div>)}</div>
      </div>
      <div className="copyright"><div className="public-container"><span>All Rights Reserved.</span><span>Copyright © {new Date().getFullYear()} niwadu.com</span></div></div>
    </footer>
    <nav className="mobile-dock" aria-label="Mobile navigation">{[...navigation, { title: "Profile", href: "https://niwadu.com/login", icon: "profile" }].map(n => <a href={n.href} key={n.href} aria-label={n.title}><Image src={`/reference/${n.icon}.svg`} alt="" width={24} height={24} /></a>)}</nav>
  </div>;
}
