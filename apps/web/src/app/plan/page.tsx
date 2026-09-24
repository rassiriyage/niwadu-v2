import type { Metadata } from "next";
import Link from "next/link";
import { PreviewNavigation, ReferenceHeader } from "../homepage-controls";
import { TripPlanner } from "./trip-planner";
import "../public.css";
import "./planner.css";

export const metadata: Metadata = {
  title: "Plan your trip around Sri Lanka | Niwadu",
  description: "Choose destinations, arrange stops and nights, and save your Sri Lanka itinerary in this browser.",
  alternates: { canonical: "https://niwadu.com/plan" },
  robots: { index: false, follow: false },
};
export default function PlanPage() {
  return <div className="public-site"><PreviewNavigation>
    <a className="skip-link" href="#planner-content">Skip to trip planner</a>
    <ReferenceHeader showCategories={false} />
    <main id="planner-content" className="public-container planner-page">
      <Link className="planner-home" href="/">← Back to explore</Link>
      <h1>Plan your trip around the island</h1>
      <p className="planner-lede">Pick your destinations, set the nights and put your itinerary together.</p>
      <TripPlanner />
      <noscript><p>Enable JavaScript to edit and save a trip in this browser. <Link href="/">Return to explore</Link>.</p></noscript>
    </main>
  </PreviewNavigation></div>;
}
