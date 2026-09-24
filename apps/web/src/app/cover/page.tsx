import type { Metadata } from "next";
import { PreviewNavigation, ReferenceHeader } from "../homepage-controls";
import { CoverageMap } from "./coverage-map";
import "../public.css";
import "../account/account.css";
import "./coverage.css";
export const metadata: Metadata = { title: "Your Sri Lanka travel map | Niwadu", robots: { index: false, follow: false } };
export default function CoverPage() {
  return <div className="public-site"><PreviewNavigation><a className="skip-link" href="#coverage-content">Skip to travel map</a><ReferenceHeader showCategories={false} /><main id="coverage-content" className="public-container coverage-page"><h1>How much of the island have you seen?</h1><p className="coverage-lede">Mark the districts you have visited. Save your private travel map to your Niwadu account.</p><CoverageMap /><noscript>Enable JavaScript to sign in and edit your private travel map.</noscript></main></PreviewNavigation></div>;
}
