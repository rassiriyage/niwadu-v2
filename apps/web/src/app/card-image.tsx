"use client";

import Image from "next/image";
import { useEffect, useRef, useState } from "react";

// Cover crops need enough pixels along BOTH axes, not just the card width.
function imageSizes(width: number, height: number) {
  const scale = Math.max(1, (width / height) / (20 / 19));
  const fixed = (px: number) => `${Math.ceil(px * scale)}px`;
  const fluid = (vw: number, gutter: number) => `calc(${(vw * scale).toFixed(4)}vw - ${(gutter * scale).toFixed(2)}px)`;
  return `(min-width: 1536px) ${fixed(224)}, (min-width: 1280px) ${fluid(16.66, 52)}, (min-width: 1024px) ${fluid(20, 48)}, (min-width: 768px) ${fluid(25, 46)}, (min-width: 640px) ${fluid(33, 40)}, ${fluid(50, 36)}`;
}

export function CardImage({ src, priority, width, height }: { src: string; priority: boolean; width: number; height: number }) {
  const sizes = imageSizes(width, height);
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
