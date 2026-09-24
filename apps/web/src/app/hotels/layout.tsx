import { PreviewNavigation, ReferenceHeader } from "../homepage-controls";
import { RefreshDiscoveryOnReturn } from "./filters";
import "../public.css";
import "./discovery.css";

export default function DiscoveryLayout({ children }: { children: React.ReactNode }) {
  return <div className="public-site"><PreviewNavigation>
    <a className="skip-link" href="#discovery-content">Skip to stays</a>
    <ReferenceHeader showCategories={false} />
    <RefreshDiscoveryOnReturn />
    <main id="discovery-content" className="public-container discovery-page">{children}</main>
  </PreviewNavigation></div>;
}
