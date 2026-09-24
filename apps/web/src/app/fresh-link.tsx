"use client";
import Link from "next/link";
import type { ReactNode } from "react";

// Public listing navigation must read the current approved revision at the origin.
export function FreshLink({ href, children, className, "aria-label": label }: { href: string; children: ReactNode; className?: string; "aria-label"?: string }) {
  return <Link href={href} prefetch={false} className={className} aria-label={label} onNavigate={event => { event.preventDefault(); window.location.assign(href); }}>{children}</Link>;
}
