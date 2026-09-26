"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import { FreshLink } from "../../fresh-link";
import { getTravellerSession, planningRequest, TravellerError, type Traveller } from "../../../lib/traveller-api";
export type Selection = { hotel_id: number; rate_plan_id: number; arrival: string; departure: string; adults: number };
type Quote = { id: string; expires_at: string; snapshot: Selection & { currency: "LKR" | "USD"; meal_plan: null | "RO" | "BB" | "HB" | "FB"; total_minor: number; policy: { version: string; text: string } } };
type Intent = { id: string; hotel_id: number; quote_id: string; state: "awaiting_hold" | "held" | "hold_released" | "hold_expired"; payment: { state: "unavailable"; reason: "payment_setup_incomplete" } };
type Envelope<T> = { data: T; meta: { checkout_enabled: false } };
const meals = { RO: "Room only (RO)", BB: "Bed and breakfast (BB)", HB: "Half board (HB)", FB: "Full board (FB)" };
const uuid = (value: unknown): value is string => typeof value === "string" && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
function readIntent(reply: Envelope<Intent>) {
  const value = reply.data;
  if (reply.meta?.checkout_enabled !== false || !value || !uuid(value.id) || !uuid(value.quote_id) || !["awaiting_hold", "held", "hold_released", "hold_expired"].includes(value.state) || value.payment?.state !== "unavailable" || value.payment.reason !== "payment_setup_incomplete") throw new TravellerError("This plan status could not be read.", 503);
  return value;
}
function readQuote(reply: Envelope<{ state: string; quote: Quote | null }>, selection: Selection) {
  if (reply.meta?.checkout_enabled !== false) throw new TravellerError("This quote could not be read.", 503);
  if (reply.data?.state === "unavailable" && reply.data.quote === null) return null;
  const value = reply.data?.quote;
  const snapshot = value?.snapshot;
  if (reply.data?.state !== "available" || !value || !uuid(value.id) || !Number.isFinite(Date.parse(value.expires_at)) || !snapshot || Object.entries(selection).some(([key, selected]) => snapshot[key as keyof Selection] !== selected) || !["LKR", "USD"].includes(snapshot.currency) || ![null, "RO", "BB", "HB", "FB"].includes(snapshot.meal_plan) || !Number.isSafeInteger(snapshot.total_minor) || snapshot.total_minor < 0 || typeof snapshot.policy?.text !== "string" || typeof snapshot.policy?.version !== "string") throw new TravellerError("The quote did not match this selection. Request a new quote.", 503);
  return value;
}
export function PlanningPanel({ selection, intentId, hotelName }: { selection?: Selection; intentId?: string; hotelName?: string }) {
  const [user, setUser] = useState<Traveller | null>(null);
  const [checking, setChecking] = useState(true);
  const [busy, setBusy] = useState(false);
  const [quote, setQuote] = useState<Quote | null>(null);
  const [intent, setIntent] = useState<Intent | null>(null);
  const [error, setError] = useState("");
  const [unavailable, setUnavailable] = useState(false);
  const [retrying, setRetrying] = useState(false);
  const [now, setNow] = useState(0);
  const [backoff, setBackoff] = useState(0);
  const identity = useRef<number | null>(null);
  const epoch = useRef(0);
  const running = useRef(false);
  const verification = useRef(0);
  const retryVerification = useRef<() => void>(() => {});
  const pending = useRef<{ quote_id: string; key: string } | null>(null);
  const clearPrivate = useCallback(() => {
    epoch.current++; verification.current++; setChecking(false); identity.current = null; pending.current = null; running.current = false;
    setUser(null); setQuote(null); setIntent(null); setRetrying(false); setBusy(false); setUnavailable(false);
  }, []);
  useEffect(() => {
    let active = true;
    const generation = epoch;
    const inspect = async () => {
      const current = epoch.current;
      const ticket = ++verification.current;
      const isCurrent = () => active && current === epoch.current && ticket === verification.current;
      setChecking(true);
      try {
        const session = await getTravellerSession();
        if (!isCurrent()) return;
        if (identity.current !== null && session.user?.id !== identity.current) {
          clearPrivate(); setError("The signed-in account changed. Private plan details were cleared. Reload to continue."); return;
        }
        identity.current = session.user?.id ?? null; setUser(session.user);
        if (session.user && intentId) {
          const reply = await planningRequest<Envelope<Intent>>(`booking-intents/${intentId}`, session.user.id);
          const value = readIntent(reply);
          if (value.id !== intentId) throw new TravellerError("This plan status could not be read.", 503);
          if (isCurrent()) setIntent(value);
        }
        if (isCurrent()) { setError(""); setChecking(false); }
      } catch (failure) {
        if (isCurrent()) {
          if (failure instanceof TravellerError && failure.status === 404 && intentId && failure.requestPath === `me/booking-intents/${intentId}`) { setIntent(null); setError("This stay plan is not available to this account."); setChecking(false); }
          else if (failure instanceof TravellerError && failure.status === 401) { clearPrivate(); setError("Your session ended. Private plan details were cleared."); }
          else { setError("Your account or plan could not be verified. Private details are hidden. Retry the account check to continue."); }
        }
      }
    };
    retryVerification.current = () => { void inspect(); };
    const hide = () => { verification.current++; setChecking(true); };
    const visible = () => { if (document.visibilityState === "visible") void inspect(); else hide(); };
    void inspect();
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    window.addEventListener("focus", inspect); window.addEventListener("pageshow", inspect); window.addEventListener("pagehide", hide); document.addEventListener("visibilitychange", visible);
    return () => { active = false; generation.current++; window.clearInterval(timer); window.removeEventListener("focus", inspect); window.removeEventListener("pageshow", inspect); window.removeEventListener("pagehide", hide); document.removeEventListener("visibilitychange", visible); };
  }, [clearPrivate, intentId]);
  useEffect(() => {
    const warn = (event: BeforeUnloadEvent) => { if (pending.current) event.preventDefault(); };
    window.addEventListener("beforeunload", warn); return () => window.removeEventListener("beforeunload", warn);
  }, []);
  async function run(job: () => Promise<void>, saving = false) {
    if (running.current || checking || Date.now() < backoff) return;
    running.current = true; setBusy(true); setError("");
    const current = epoch.current;
    try { await job(); }
    catch (failure) {
      if (current !== epoch.current) return;
      if (failure instanceof TravellerError && failure.status === 401) clearPrivate();
      if (failure instanceof TravellerError && failure.status === 429) setBackoff(Date.now() + (failure.retryAfter ?? 60) * 1000);
      if (saving && failure instanceof TravellerError && ["quote_expired", "quote_changed", "quote_unavailable"].includes(failure.code || "")) { pending.current = null; setRetrying(false); setQuote(null); }
      else if (saving && identity.current !== null) setRetrying(true);
      setError(failure instanceof Error ? failure.message : "The request could not be completed.");
    } finally { if (current === epoch.current) { running.current = false; setBusy(false); } }
  }
  function requestQuote() {
    if (!user || !selection || pending.current) return;
    const current = epoch.current;
    setQuote(null); setIntent(null); setUnavailable(false);
    void run(async () => {
      const reply = await planningRequest<Envelope<{ state: string; quote: Quote | null }>>("booking-quotes", user.id, selection);
      const value = readQuote(reply, selection);
      if (current === epoch.current) { setQuote(value); setUnavailable(value === null); setNow(Date.now()); }
    });
  }
  function save() {
    if (!user || !quote || running.current || checking || Date.now() < backoff || (!pending.current && Date.now() >= Date.parse(quote.expires_at))) return;
    pending.current ??= { quote_id: quote.id, key: crypto.randomUUID() };
    const original = pending.current;
    const current = epoch.current;
    void run(async () => {
      const reply = await planningRequest<Envelope<Intent>>("booking-intents", user.id, { quote_id: original.quote_id }, original.key);
      const value = readIntent(reply);
      if (value.quote_id !== original.quote_id || value.hotel_id !== selection?.hotel_id) throw new TravellerError("The saved plan did not match this quote. Retry the original save.", 503);
      if (current === epoch.current) { setIntent(value); setQuote(null); pending.current = null; setRetrying(false); }
    }, true);
  }
  const expired = quote ? now >= Date.parse(quote.expires_at) : false;
  const blocked = busy || checking || now < backoff;
  return <section className="discovery-state planning-panel" aria-busy={busy || checking}>
    {selection && <p>{hotelName || "Selected stay"} · {selection.arrival} to {selection.departure} · {selection.adults} adults · one room</p>}
    <p>Planning only. No booking confirmation or online payment is available.</p>
    <FreshLink className="discovery-button" href="/hotels?sort=name">Browse stays</FreshLink>
    {error && <p role="alert">{error}</p>}
    {checking ? <><p role="status">Checking your account…</p>{error && <button className="discovery-button" onClick={() => retryVerification.current()}>Retry account check</button>}</> : !user ? <p><a className="discovery-button" href="/account" target="_blank" rel="noopener noreferrer">Sign in</a> (opens another tab), then return here or reload. Your selected dates stay in this page’s URL.</p> : <>
      <p>Signed in as {user.name}</p>
      {selection && !intent && <button className="discovery-button" disabled={blocked || retrying} onClick={requestQuote}>Request current quote</button>}
      {unavailable && <p role="status">This selection is unavailable. Nothing reserved. Your dates and selection are unchanged.</p>}
      {quote && <section className="planning-quote"><h2>Quote review</h2><p>Nothing reserved.</p><p>{new Intl.NumberFormat("en-LK", { style: "currency", currency: quote.snapshot.currency, currencyDisplay: "code" }).format(quote.snapshot.total_minor / 100)} total, including mandatory taxes and fees</p><p>{quote.snapshot.meal_plan ? meals[quote.snapshot.meal_plan] : "Meal plan not specified"}</p><p style={{ whiteSpace: "pre-wrap" }}>{quote.snapshot.policy.text}</p><p>Quote expires at <time dateTime={quote.expires_at}>{quote.expires_at}</time>.</p>{expired && <p role="status">This quote has expired. {retrying ? "You can still retry the original save." : "Request a current quote before making a new save."}</p>}
        {retrying ? <><p>The original save has not been confirmed. Retry the same save; do not create a new plan. Leaving this page loses the retry key.</p><button className="discovery-button" disabled={blocked} onClick={save}>Retry original save</button></> : <button className="primary" disabled={blocked || expired} onClick={save}>Save stay plan</button>}
      </section>}
      {intent && <section aria-label="Plan status"><p role="status">{intent.state === "held" ? "A temporary stock hold is reported. This is not a confirmed booking." : intent.state === "awaiting_hold" ? "Stay plan saved. Nothing reserved." : intent.state === "hold_expired" ? "The temporary hold expired. Nothing reserved." : "The temporary hold was released. Nothing reserved."}</p><p>Plan reference: {intent.id}</p><p>Online payment is unavailable.</p>{intentId ? <p>Original prices and policies are not available on this status page. This status does not confirm current availability.</p> : <FreshLink className="discovery-button" href={`/plan/intents/${intent.id}`}>Open saved plan status</FreshLink>}</section>}
      {now < backoff && <p role="status">Too many attempts. Wait before trying again.</p>}
    </>}
  </section>;
}
