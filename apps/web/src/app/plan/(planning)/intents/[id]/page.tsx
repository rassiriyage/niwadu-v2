import { notFound } from "next/navigation";
import { PlanningPanel } from "../../planning-panel";
export default async function IntentPage({ params }: { params: Promise<{ id: string }> }) {
  if (process.env.BOOKING_PLANNING_ENABLED !== "true") notFound();
  const { id } = await params;
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)) notFound();
  return <><h1>Saved stay plan</h1><PlanningPanel intentId={id} /></>;
}
