import type { Metadata } from "next";
import "./admin.css";

export const metadata: Metadata = { title: "Niwadu · Hotel management", robots: { index: false, follow: false }, referrer: "no-referrer" };

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  return <div className="admin-app">{children}</div>;
}
