"use client";

import { useRef, useState, type ReactNode } from "react";

export function SearchPreview() {
  const dialog = useRef<HTMLDialogElement>(null);
  return <>
    <button className="mobile-search" onClick={() => dialog.current?.showModal()}>Start your search <span className="search-icon" aria-hidden="true" /></button>
    <div className="desktop-search" aria-label="Search unavailable">
      <div><strong>Where</strong><span>Search unavailable</span></div>
      <div><strong>Check In</strong><span>Add dates</span></div>
      <div><strong>Check Out</strong><span>Add dates</span></div>
      <div><strong>Who</strong><span>Add guests</span></div>
      <button disabled aria-label="Search unavailable"><span className="search-icon" aria-hidden="true" /></button>
    </div>
    <dialog ref={dialog} className="search-preview" aria-labelledby="search-unavailable-title" onKeyDown={event => {
      if (event.key !== "Tab") return;
      const controls = event.currentTarget.querySelectorAll<HTMLElement>("button, a[href]");
      const first = controls[0], last = controls[controls.length - 1];
      if ((event.shiftKey && event.target === first) || (!event.shiftKey && event.target === last)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
      }
    }}>
      <button className="close-search" onClick={() => dialog.current?.close()}>Close</button>
      <h2 id="search-unavailable-title">Search unavailable</h2>
      <p>You can browse hotels on Niwadu.</p>
      <a href="https://niwadu.com/hotels">Browse hotels</a>
    </dialog>
  </>;
}

export function Carousel({ id, title, href, kind, children }: { id: string; title: string; href: string; kind: string; children: ReactNode }) {
  const track = useRef<HTMLUListElement>(null);
  const [edges, setEdges] = useState({ start: true, end: false });
  function updateEdges() {
    const el = track.current;
    if (el) setEdges({ start: el.scrollLeft < 2, end: el.scrollLeft + el.clientWidth >= el.scrollWidth - 2 });
  }
  function move(direction: number) {
    const el = track.current;
    if (!el) return;
    const card = el.firstElementChild;
    el.scrollBy({ left: direction * ((card?.getBoundingClientRect().width ?? el.clientWidth) + 16), behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "instant" : "smooth" });
  }
  return <section className={`collection ${kind}`} aria-labelledby={`${id}-title`}>
    <h2 id={`${id}-title`}><a href={href}>{title}<span className="chevron" aria-hidden="true" /></a></h2>
    <div className="carousel-controls">
      <button aria-label={`Previous ${title}`} aria-controls={id} disabled={edges.start} onClick={() => move(-1)}><span className="chevron left" aria-hidden="true" /></button>
      <button aria-label={`Next ${title}`} aria-controls={id} disabled={edges.end} onClick={() => move(1)}><span className="chevron" aria-hidden="true" /></button>
    </div>
    <ul id={id} className="card-track" ref={track} onScroll={updateEdges} tabIndex={0} aria-label={`${title} listings`}>{children}</ul>
  </section>;
}
