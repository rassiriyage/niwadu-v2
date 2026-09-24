"use client";
import { useCallback, useEffect, useState, type MutableRefObject } from "react";
import { api, ApiError, type Hotel, type Session } from "@/lib/admin-api";
import { canLeaveOperations, OperationsContext, type Request } from "./forms";
import Manual from "./manual";
import Pms from "./pms";

export default function Operations({ id, userId, beforeLeaveRef }: { id: number; userId: number; beforeLeaveRef: MutableRefObject<(() => Promise<void>) | null> }) {
  const [hotel, setHotel] = useState<Hotel>(), [error, setError] = useState(""), [expired, setExpired] = useState(false), [view, setView] = useState("manual");
  const request: Request = useCallback(async <T,>(path: string, method = "GET", data?: unknown): Promise<T> => {
    try {
      if ((await api<Session>("session")).user?.id !== userId) throw new ApiError("Your account changed. Sign in again before editing this hotel.", 401);
      const result = await api<T>(path, method, data);
      if ((await api<Session>("session")).user?.id !== userId) throw new ApiError("Your account changed. Sign in again before editing this hotel.", 401);
      return result;
    } catch (e) { if (e instanceof ApiError && [401, 419].includes(e.status)) setExpired(true); throw e; }
  }, [userId]);
  useEffect(() => { let active = true; Promise.resolve().then(() => request<{ data: Hotel }>(`hotels/${id}`)).then(r => { if (active) setHotel(r.data); }).catch(e => { if (active) setError(e.message); }); return () => { active = false; }; }, [id, request]);
  useEffect(() => {
    const dirty = () => !!document.querySelector(".ops-form[data-unsaved], .ops-form[data-saving]");
    const beforeUnload = (event: BeforeUnloadEvent) => { if (dirty()) { event.preventDefault(); event.returnValue = ""; } };
    const beforeLink = (event: MouseEvent) => { if ((event.target as Element).closest("a[href]") && !canLeaveOperations()) { event.preventDefault(); event.stopImmediatePropagation(); } };
    beforeLeaveRef.current = async () => { if (dirty()) throw new Error("Save or discard the current form before signing out."); };
    window.addEventListener("beforeunload", beforeUnload); document.addEventListener("click", beforeLink, true);
    return () => { beforeLeaveRef.current = null; window.removeEventListener("beforeunload", beforeUnload); document.removeEventListener("click", beforeLink, true); };
  }, [beforeLeaveRef]);
  if (expired) return <section className="panel"><h1>Sign in again</h1><p role="alert">Your session ended or changed. Private hotel data and credential inputs have been cleared.</p><a href="/admin">Return to sign in</a></section>;
  if (!hotel) return <p role={error ? "alert" : "status"}>{error || "Loading hotel configuration…"}</p>;
  return <OperationsContext.Provider value={request}><a className="back-link" href={`/admin/hotels/${id}`}>← Hotel profile</a><div className="page-heading"><div><p className="eyebrow">HOTEL CONFIGURATION</p><h1>{hotel.name}</h1><p>Rooms, dated rates and inventory settings</p></div></div><p className="notice">Configuration does not publish this hotel, reserve rooms or process payments.</p>
    {hotel.permissions.manage_pms && <nav className="setup-steps" aria-label="Configuration area"><button aria-pressed={view === "manual"} className="secondary" onClick={() => { if (canLeaveOperations()) setView("manual"); }}>Manual inventory</button><button aria-pressed={view === "pms"} className="secondary" onClick={() => { if (canLeaveOperations()) setView("pms"); }}>PMS settings</button></nav>}
    {view === "pms" && hotel.permissions.manage_pms ? <Pms hotelId={id} /> : <Manual hotel={hotel} />}
  </OperationsContext.Provider>;
}
