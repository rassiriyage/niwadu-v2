"use client";
import { DISTRICTS, MAP_VIEWBOX, project } from "./reference-districts";

export type MapPin = { lat: number; lng: number; label?: string; href?: string; active?: boolean; index?: number; /** Text for an Airbnb style price pill instead of a numbered dot. */ pill?: string };

type Props = {
  className?: string;
  /** District slugs to paint as visited. */
  visited?: Set<string> | string[];
  /** District slug currently highlighted. */
  active?: string | null;
  onDistrictClick?: (slug: string) => void;
  onDistrictHover?: (slug: string | null) => void;
  pins?: MapPin[];
  /** Draw a route through these pins, in order. */
  route?: { lat: number; lng: number }[];
  labels?: boolean;
  onPinClick?: (i: number) => void;
};

/**
 * The island, 25 districts, as an SVG. Everything else on the map is drawn in
 * the same projected coordinate space so pins and routes line up with borders.
 */
export function SriLankaMap({ className = "", visited, active, onDistrictClick, onDistrictHover, pins = [], route, labels = false, onPinClick }: Props) {
  const v = visited instanceof Set ? visited : new Set(visited ?? []);
  const clickable = Boolean(onDistrictClick);
  return (
    <svg viewBox={MAP_VIEWBOX} className={className} role="img" aria-label="Map of Sri Lanka by district">
      <g>
        {DISTRICTS.map((d) => (
          <path
            key={d.slug}
            d={d.path}
            className={`district${v.has(d.slug) ? " is-visited" : ""}${active === d.slug ? " is-active" : ""}${clickable ? " is-clickable" : ""}`}
            onClick={clickable ? () => onDistrictClick?.(d.slug) : undefined}
            onMouseEnter={onDistrictHover ? () => onDistrictHover(d.slug) : undefined}
            onMouseLeave={onDistrictHover ? () => onDistrictHover(null) : undefined}
            tabIndex={clickable ? 0 : undefined}
            role={clickable ? "button" : undefined}
            aria-pressed={clickable ? v.has(d.slug) : undefined}
            aria-label={clickable ? d.name : undefined}
            onKeyDown={clickable ? (e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); onDistrictClick?.(d.slug); } } : undefined}
          >
            <title>{d.name}</title>
          </path>
        ))}
      </g>
      {labels && (
        <g className="pointer-events-none select-none" fontSize="9" fontWeight="600" fill="#334155" textAnchor="middle">
          {DISTRICTS.map((d) => (
            <text key={d.slug} x={d.cx} y={d.cy} dy="3">{d.name}</text>
          ))}
        </g>
      )}
      {route && route.length > 1 && (
        <polyline
          points={route.map((p) => { const { x, y } = project(p.lat, p.lng); return `${x},${y}`; }).join(" ")}
          fill="none" stroke="#222" strokeWidth="3" strokeLinejoin="round" strokeLinecap="round" strokeDasharray="1 7"
        />
      )}
      {pins.map((p, i) => {
        const { x, y } = project(p.lat, p.lng);
        const num = p.index ?? i + 1;
        const w = p.pill ? Math.max(38, p.pill.length * 6.2 + 14) : 0;
        const body = p.pill ? (
          <g transform={`translate(${x} ${y})`} className={onPinClick || p.href ? "cursor-pointer" : ""} onClick={onPinClick ? () => onPinClick(i) : undefined}>
            <rect x={-w / 2} y={-11} width={w} height={22} rx={11} fill={p.active ? "#222" : "#fff"} stroke="rgba(0,0,0,.18)" strokeWidth="1" style={{ filter: "drop-shadow(0 1px 2px rgba(0,0,0,.18))" }} />
            <text textAnchor="middle" dy="4" fontSize="10.5" fontWeight="700" fill={p.active ? "#fff" : "#222"}>{p.pill}</text>
            {p.label && <title>{p.label}</title>}
          </g>
        ) : (
          <g transform={`translate(${x} ${y})`} className={onPinClick ? "cursor-pointer" : ""} onClick={onPinClick ? () => onPinClick(i) : undefined}>
            <circle r="11" fill={p.active ? "#ff385c" : "#222"} stroke="#fff" strokeWidth="2.5" />
            <text textAnchor="middle" dy="3.5" fontSize="10" fontWeight="700" fill="#fff">{num}</text>
            {p.label && <title>{p.label}</title>}
          </g>
        );
        return p.href ? <a key={i} href={p.href}>{body}</a> : <g key={i}>{body}</g>;
      })}
    </svg>
  );
}
