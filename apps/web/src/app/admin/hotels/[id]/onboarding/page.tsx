import { notFound } from "next/navigation";
import Workspace from "../../../workspace";
export default async function OnboardingPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!/^\d+$/.test(id)) notFound();
  return <Workspace hotelId={Number(id)} onboarding />;
}
