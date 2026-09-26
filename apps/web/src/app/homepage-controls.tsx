"use client";

import Link from "next/link";
import { CurrencySelector } from "./currency-selector";
import { FreshLink } from "./fresh-link";
import { AnimatedWordmark } from "./Logo";
import { createContext, useContext, useRef, useState, type ReactNode, type PointerEvent } from "react";

const PreviewContext = createContext<(label: string) => void>(() => {});

export function PreviewNavigation({ children }: { children: ReactNode }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const [label, setLabel] = useState("");
  return <PreviewContext.Provider value={title => { setLabel(title); dialog.current?.showModal(); }}>
    {children}
    <dialog ref={dialog} className="navigation-preview" aria-labelledby="preview-title" aria-describedby="preview-description" onKeyDown={event => {
      if (event.key === "Tab") { event.preventDefault(); event.currentTarget.querySelector<HTMLButtonElement>("button")?.focus(); }
    }}>
      <h2 id="preview-title">{label} — preview only</h2>
      <p id="preview-description">This page is not available in this preview yet. You can continue exploring the homepage.</p>
      <button onClick={() => dialog.current?.close()}>Close</button>
    </dialog>
  </PreviewContext.Provider>;
}

export function PreviewAction({ label, accessibleName, className, children }: { label: string; accessibleName?: string; className?: string; children: ReactNode }) {
  const showPreview = useContext(PreviewContext);
  const theme = ({ Beach: "beach", "Hill country": "hills", Wildlife: "wild", "Cultural triangle": "culture", Adventure: "adventure", "City breaks": "city" } as Record<string, string>)[label];
  if (theme || label === "North & East") return <FreshLink href={theme ? `/hotels?themes%5B%5D=${theme}&sort=name` : "/hotels?region=north-east&sort=name"} className={className} aria-label={accessibleName}>{children}</FreshLink>;
  if (label === "Cover Sri Lanka") return <FreshLink href="/cover" className={className} aria-label={accessibleName}>{children}</FreshLink>;
  if (["Profile", "Log In", "Sign Up"].includes(label)) return <FreshLink href={label === "Sign Up" ? "/account?mode=register" : "/account"} className={className} aria-label={accessibleName}>{children}</FreshLink>;
  if (label === "All stays" || label === "Search stays") return <FreshLink href="/hotels?sort=name" className={className} aria-label={accessibleName}>{children}</FreshLink>;
  if (label === "Plan a trip" || label === "Trip planner") return <Link href="/plan" className={className} aria-label={accessibleName}>{children}</Link>;
  return <button type="button" className={className} aria-label={accessibleName} onClick={() => showPreview(label)}>{children}</button>;
}

export function Carousel({ id, title, kind, children }: { id: string; title: string; kind: string; children: ReactNode }) {
  const track = useRef<HTMLUListElement>(null);
  const [edges, setEdges] = useState({ start: true, end: false });
  const gesture = useRef<{ id: number; x: number; y: number; left: number; dragging: boolean } | null>(null);
  const suppressClick = useRef(false);
  const [dragging, setDragging] = useState(false);
  function finishDrag(event: PointerEvent<HTMLUListElement>) {
    if (gesture.current?.id !== event.pointerId) return;
    gesture.current = null;
    setDragging(false);
    if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
  }
  function drag(event: PointerEvent<HTMLUListElement>) {
    const current = gesture.current;
    if (!current || current.id !== event.pointerId) return;
    if (!(event.buttons & 1)) { finishDrag(event); return; }
    const dx = event.clientX - current.x;
    const dy = event.clientY - current.y;
    if (!current.dragging) {
      if (Math.abs(dx) < 6) return;
      if (Math.abs(dy) > Math.abs(dx)) { finishDrag(event); return; }
      current.dragging = true;
      suppressClick.current = true;
      setDragging(true);
      event.currentTarget.setPointerCapture(event.pointerId);
    }
    event.preventDefault();
    event.currentTarget.scrollLeft = current.left - dx;
  }
  function updateEdges() {
    const el = track.current;
    if (el) setEdges({ start: el.scrollLeft < 2, end: el.scrollLeft + el.clientWidth >= el.scrollWidth - 2 });
  }
  function move(direction: number) {
    const el = track.current;
    if (!el) return;
    const card = el.firstElementChild;
    el.scrollBy({ left: direction * ((card?.getBoundingClientRect().width ?? el.clientWidth) + 24), behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "instant" : "smooth" });
  }
  return <section className={`collection ${kind}`} aria-labelledby={`${id}-title`}>
    <h2 id={`${id}-title`}><PreviewAction label={title}>{title}<span className="chevron" aria-hidden="true" /></PreviewAction></h2>
    <div className="carousel-controls">
      <button aria-label={`Previous ${title}`} aria-controls={id} disabled={edges.start} onClick={() => move(-1)}><span className="chevron left" aria-hidden="true" /></button>
      <button aria-label={`Next ${title}`} aria-controls={id} disabled={edges.end} onClick={() => move(1)}><span className="chevron" aria-hidden="true" /></button>
    </div>
    <ul id={id} className="card-track" data-dragging={dragging || undefined} ref={track} onScroll={updateEdges} tabIndex={0} aria-label={`${title} listings`}
      onPointerDown={event => {
        suppressClick.current = false;
        // Touch keeps native horizontal swiping and vertical page scrolling.
        if (event.pointerType === "touch" || event.button !== 0 || !event.isPrimary) return;
        gesture.current = { id: event.pointerId, x: event.clientX, y: event.clientY, left: event.currentTarget.scrollLeft, dragging: false };
      }}
      onPointerMove={drag} onPointerUp={finishDrag} onPointerCancel={finishDrag} onLostPointerCapture={finishDrag}
      onPointerLeave={event => { if (!gesture.current?.dragging) finishDrag(event); }}
      onDragStart={event => event.preventDefault()}
      onClickCapture={event => {
        if (suppressClick.current && event.detail > 0) { event.preventDefault(); event.stopPropagation(); }
      }}
    >{children}</ul>
  </section>;
}

const categories = [
  ["All stays", "M3 11 12 3l9 8M5 10v10h14V10M10 20v-6h4v6"],
  ["Beach", "M4 20h16M6 20c0-4 3-9 8-11M14 9l4-3M14 9c-3-2-7-1-9 2m9-2c3-2 7-1 9 2M14 9l-2 11"],
  ["Hill country", "M3 20 10 6l4 7 3-4 4 11H3z"],
  ["Wildlife", "M4 18v-6a6 6 0 0 1 12 0v6M4 12H2m14 0h3a2 2 0 0 1 2 2v4M8 18v3m4-3v3M10 11h.01"],
  ["Cultural triangle", "M12 3 4 8h16l-8-5zM5 8v10m4-10v10m6-10v10m4-10v10M3 18h18M3 21h18"],
  ["Adventure", "M3 17 9 5l3 6 2-3 7 9H3zM6 21l2-2 2 2 2-2 2 2 2-2 2 2"],
  ["North & East", "M3 12c2-2 4-2 6 0s4 2 6 0 4-2 6 0M3 17c2-2 4-2 6 0s4 2 6 0 4-2 6 0M12 3v6m-3-3 3-3 3 3"],
  ["City breaks", "M3 21h18M5 21V7l5-3v17M10 21V4l9 4v13M7 10h1m-1 4h1m5-4h1m-1 4h1m2-4h1m-1 4h1"],
];
export function ReferenceHeader({ showCategories = true }: { showCategories?: boolean }) {
  const menu = useRef<HTMLDetailsElement>(null);
  return <><header className="site-header"><div className="public-container header-nav">
    <Link href="/" className="site-logo" aria-label="Niwadu home"><AnimatedWordmark /></Link>
    <div className="search-center"><PreviewAction className="search-pill" label="Search stays"><svg viewBox="0 0 32 32" aria-hidden="true"><path d="M13 24a11 11 0 1 0 0-22 11 11 0 0 0 0 22zm8-3 9 9" /></svg><span className="desktop-search-copy"><strong>Anywhere</strong><i/><strong>Any week</strong><i/><span>Add guests</span></span><span className="mobile-search-copy"><strong>Where to?</strong><span>Anywhere · Any week · Add guests</span></span></PreviewAction></div>
    <div className="header-actions"><PreviewAction className="plan-nav" label="Plan a trip">Plan a trip</PreviewAction><CurrencySelector />
      <details ref={menu} className="menu" onKeyDown={event => { if(event.key === "Escape" && menu.current?.open) { menu.current.open=false; menu.current.querySelector("summary")?.focus(); } }}><summary aria-label="Menu"><svg viewBox="0 0 32 32" aria-hidden="true"><path d="M2 8h28M2 16h28M2 24h28" /></svg><span className="profile-icon"><svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 .7C7.56.7.7 7.56.7 16S7.56 31.3 16 31.3 31.3 24.44 31.3 16 24.44.7 16 .7zm0 28c-4.02 0-7.6-1.88-9.93-4.81a12.43 12.43 0 0 1 6.45-4.4A6.5 6.5 0 0 1 9.5 14a6.5 6.5 0 1 1 13 0 6.5 6.5 0 0 1-3.02 5.49 12.42 12.42 0 0 1 6.45 4.4A12.67 12.67 0 0 1 16 28.7z"/></svg></span></summary><nav aria-label="Main navigation">{["Wishlists", "Plan a trip", "Cover Sri Lanka", "All stays", "Destinations", "Experiences", "About Niwadu", "Help Centre", "Contact", "Profile", "Log In", "Sign Up"].map(label => <PreviewAction key={label} label={label}>{label}</PreviewAction>)}</nav></details>
    </div>
  </div></header>{showCategories && <nav className="categories" aria-label="Stay categories"><div className="public-container">{categories.map(([label,path]) => <PreviewAction key={label} label={label}><svg viewBox="0 0 24 24" aria-hidden="true"><path d={path}/></svg><span>{label}</span></PreviewAction>)}</div></nav>}</>;
}
