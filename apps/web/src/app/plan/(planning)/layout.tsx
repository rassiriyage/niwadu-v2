import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { PreviewNavigation, ReferenceHeader } from "../../homepage-controls";
import "../../public.css";
import "../../hotels/discovery.css";
export const dynamic = "force-dynamic";
export const metadata: Metadata = { title: "Stay planning | Niwadu", robots: { index: false, follow: false } };
export default function PlanningLayout({ children }: { children: React.ReactNode }) {
  if (process.env.BOOKING_PLANNING_ENABLED !== "true") notFound();
  return <div className="public-site"><PreviewNavigation><ReferenceHeader showCategories={false} showCurrency={false} /><main className="public-container discovery-page">{children}</main></PreviewNavigation></div>;
}
