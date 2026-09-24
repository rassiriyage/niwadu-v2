import type { Metadata } from "next";
import { PreviewNavigation, ReferenceHeader } from "../../homepage-controls";
import { SavedItineraries } from "./saved-itineraries";
import "../../public.css";
import "../planner.css";
import "./saved.css";
export const metadata: Metadata = { title: "My saved trips | Niwadu", robots: { index: false, follow: false } };
export default function SavedTripsPage() {
  return <div className="public-site"><PreviewNavigation><a className="skip-link" href="#saved-trips">Skip to saved trips</a><ReferenceHeader showCategories={false} /><main id="saved-trips" className="public-container planner-page"><h1>My saved trips</h1><p className="planner-lede">Private itineraries saved to your account. No stays or transport are reserved.</p><SavedItineraries /><noscript>Enable JavaScript to manage your saved itineraries.</noscript></main></PreviewNavigation></div>;
}
