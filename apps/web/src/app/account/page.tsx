import type { Metadata } from "next";
import { PreviewNavigation, ReferenceHeader } from "../homepage-controls";
import { AccountPanel } from "./account-panel";
import "../public.css";
import "./account.css";
export const metadata: Metadata = { title: "Your Niwadu account", robots: { index: false, follow: false } };
export default async function AccountPage({ searchParams }: { searchParams: Promise<{ mode?: string }> }) {
  const { mode } = await searchParams;
  return <div className="public-site"><PreviewNavigation><a className="skip-link" href="#account-content">Skip to account</a><ReferenceHeader showCategories={false} /><main id="account-content" className="public-container account-page"><AccountPanel register={mode === "register"} /><noscript>Enable JavaScript to sign in to your account.</noscript></main></PreviewNavigation></div>;
}
