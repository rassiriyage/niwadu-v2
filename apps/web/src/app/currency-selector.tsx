"use client";

import { useSyncExternalStore } from "react";

const subscribe = () => () => {};
function currentCurrency() {
  const url = new URL(window.location.href);
  const explicit = /^\/hotels\/[^/]+$/.test(url.pathname) ? url.searchParams.get("currency") : null;
  const preferred = document.cookie.split("; ").find(item => item.startsWith("niwadu.currency="))?.split("=")[1];
  return (explicit || preferred) === "USD" ? "USD" : "LKR";
}

export function CurrencySelector() {
  const currency = useSyncExternalStore(subscribe, currentCurrency, () => "LKR");
  return <select className="currency" aria-label="Currency" value={currency} onChange={event => {
    const selected = event.currentTarget.value;
    document.cookie = `niwadu.currency=${selected}; Path=/; Max-Age=31536000; SameSite=Lax${location.protocol === "https:" ? "; Secure" : ""}`;
    const url = new URL(window.location.href);
    if (/^\/hotels\/[^/]+$/.test(url.pathname)) url.searchParams.set("currency", selected);
    window.location.assign(url.href);
  }}><option value="LKR">LKR</option><option value="USD">USD</option></select>;
}
