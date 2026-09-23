"use client";

import Image from "next/image";
import { useEffect, useRef, useState } from "react";

const sizes = "(min-width: 1536px) 224px, (min-width: 1280px) calc(16.66vw - 52px), (min-width: 1024px) calc(20vw - 48px), (min-width: 768px) calc(25vw - 46px), (min-width: 640px) calc(33vw - 40px), calc(50vw - 36px)";

export function CardImage({ src, priority }: { src: string; priority: boolean }) {
  const slot = useRef<HTMLSpanElement>(null);
  const [visible, setVisible] = useState(priority);
  useEffect(() => {
    if (priority || !slot.current) return;
    // Native lazy loading prefetches this entire short homepage in Chromium.
    // Observe the clipped card slot to defer lower rows and horizontal overflow.
    const observer = new IntersectionObserver(entries => {
      if (entries.some(entry => entry.isIntersecting)) {
        setVisible(true);
        observer.disconnect();
      }
    }, { rootMargin: "300px 100px" });
    observer.observe(slot.current);
    return () => observer.disconnect();
  }, [priority]);
  return <span ref={slot} className="photo-slot">
    {visible && <Image src={src} alt="" fill quality={90} loading={priority ? "eager" : "lazy"} fetchPriority={priority ? "high" : undefined} sizes={sizes} />}
    <noscript><Image src={src} alt="" fill quality={90} sizes={sizes} /></noscript>
  </span>;
}
