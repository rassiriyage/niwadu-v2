"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import { DISTRICTS, PROVINCES } from "../reference-districts";
import { SriLankaMap } from "../SriLankaMap";
import { FreshLink } from "../fresh-link";
import { getTravellerSession, travellerRead, travellerWrite, TravellerError, type Traveller, type Coverage } from "../../lib/traveller-api";

const known = new Set(DISTRICTS.map(district => district.slug));
function validateCoverage(value: Coverage) {
  if (!value || typeof value !== "object" || !Number.isSafeInteger(value.version) || value.version < 0 || !Array.isArray(value.districts) || value.districts.length > 25 || new Set(value.districts).size !== value.districts.length || value.districts.some(slug => !known.has(slug))) throw new TravellerError("Your saved map could not be read. Please reload it.", 503);
  return { districts: [...value.districts].sort(), version: value.version };
}
async function readOwnCoverage(id: number) {
  if ((await getTravellerSession()).user?.id !== id) throw new TravellerError("Your signed-in account changed. Please sign in again.", 401);
  const coverage = validateCoverage(await travellerRead<Coverage>("me/coverage"));
  if ((await getTravellerSession()).user?.id !== id) throw new TravellerError("Your signed-in account changed. Please sign in again.", 401);
  return coverage;
}
export function CoverageMap() {
  const [user, setUser] = useState<Traveller | null>(null);
  const [coverage, setCoverage] = useState<Coverage | null>(null);
  const [selected, setSelected] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [needsReview, setNeedsReview] = useState(false);
  const [latest, setLatest] = useState<Coverage | null>(null);
  const [clear, setClear] = useState(false);
  const identity = useRef<number | null>(null);
  const requestVersion = useRef(0);
  const dirty = coverage !== null && JSON.stringify([...selected].sort()) !== JSON.stringify(coverage.districts);
  const clearIdentity = useCallback((message: string) => {
    requestVersion.current++; identity.current = null; setUser(null); setCoverage(null); setSelected([]); setLatest(null); setNeedsReview(false); setError(message); setNotice(""); setBusy(false); setLoading(false);
  }, []);
  const load = useCallback(() => {
    const version = ++requestVersion.current;
    return getTravellerSession().then(async session => {
      if (version !== requestVersion.current) return;
      setError("");
      if (!session.user) { clearIdentity(""); return; }
      const saved = await readOwnCoverage(session.user.id);
      if (version !== requestVersion.current) return;
      identity.current = session.user.id; setUser(session.user); setCoverage(saved); setSelected(saved.districts); setNeedsReview(false); setLatest(null);
    }).catch(failure => {
      if (version !== requestVersion.current) return;
      clearIdentity(failure instanceof Error ? failure.message : "Your map could not be loaded.");
    }).finally(() => { if (version === requestVersion.current) setLoading(false); });
  }, [clearIdentity]);
  useEffect(() => {
    void load();
    const generation = requestVersion;
    return () => { generation.current++; };
  }, [load]);
  useEffect(() => {
    const checkIdentity = async () => {
      const id = identity.current;
      if (id === null || document.visibilityState !== "visible") return;
      setLoading(true);
      try {
        if ((await getTravellerSession()).user?.id !== id) clearIdentity("Your session ended or the signed-in account changed. Sign in again to load your map.");
      } catch { clearIdentity("Your account could not be verified. Reload before continuing."); }
      finally { setLoading(false); }
    };
    const onReturn = () => { void checkIdentity(); };
    window.addEventListener("focus", onReturn); document.addEventListener("visibilitychange", onReturn);
    const onPageShow = (event: PageTransitionEvent) => { if (event.persisted) void checkIdentity(); };
    window.addEventListener("pageshow", onPageShow);
    return () => { window.removeEventListener("focus", onReturn); document.removeEventListener("visibilitychange", onReturn); window.removeEventListener("pageshow", onPageShow); };
  }, [clearIdentity]);
  useEffect(() => {
    if (!dirty) return;
    const leave = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ""; };
    window.addEventListener("beforeunload", leave);
    return () => window.removeEventListener("beforeunload", leave);
  }, [dirty]);
  function toggle(slug: string) {
    if (busy) return;
    setSelected(current => current.includes(slug) ? current.filter(value => value !== slug) : [...current, slug].sort());
    setNotice("");
  }
  async function save() {
    if (!user || !coverage || busy || needsReview) return;
    const version = requestVersion.current;
    setBusy(true); setError(""); setNotice("");
    try {
      const saved = validateCoverage(await travellerWrite<Coverage>("me/coverage", { districts: [...selected].sort(), version: coverage.version }, user.id));
      if (version !== requestVersion.current) return;
      if ((await getTravellerSession()).user?.id !== user.id) { clearIdentity("Your signed-in account changed. Sign in again."); return; }
      setCoverage(saved); setSelected(saved.districts); setNotice("Your travel map is saved to your account.");
    } catch (failure) {
      if (version !== requestVersion.current) return;
      if (failure instanceof TravellerError && [401, 419].includes(failure.status)) clearIdentity("Your session ended. Sign in again to load your saved map.");
      else {
        setNeedsReview(true);
        setError(failure instanceof TravellerError && failure.status === 409 ? "Your map was changed elsewhere. Your selections are still here. Compare the saved map before choosing what to keep." : "We could not confirm this save. Your selections are still here. Check the saved map before trying again.");
      }
    } finally { if (version === requestVersion.current) setBusy(false); }
  }
  async function compare() {
    if (!user || busy) return;
    const version = requestVersion.current;
    setBusy(true);
    try { const saved = await readOwnCoverage(user.id); if (version === requestVersion.current) setLatest(saved); }
    catch (failure) { if (version !== requestVersion.current) return; if (failure instanceof TravellerError && failure.status === 401) clearIdentity(failure.message); else setError("The saved map could not be loaded. Your selections are still here; try comparing again."); }
    finally { if (version === requestVersion.current) setBusy(false); }
  }
  if (loading) return <p className="coverage-loading" role="status">Loading your private travel map…</p>;
  if (!user || !coverage) return <section className="coverage-signin"><h2>Your map, saved to your account</h2><p>Sign in to mark your visited districts and find your map on another device.</p>{error && <p className="account-error" role="alert">{error}</p>}<div className="account-actions"><FreshLink className="primary" href="/account">Sign in</FreshLink><FreshLink className="account-secondary" href="/account?mode=register">Create an account</FreshLink>{error && <button className="account-secondary" onClick={() => { setLoading(true); void load(); }}>Reload map</button>}</div></section>;
  return <>
    <div className="coverage-toolbar"><p>Private map for <strong>{user.name}</strong></p><FreshLink className="account-secondary" href="/account">Your account</FreshLink></div>
    {error && <div className="account-error" role="alert"><p>{error}</p>{needsReview && <button disabled={busy} onClick={() => void compare()}>{busy ? "Checking…" : "Compare saved map"}</button>}</div>}
    {latest && <section className="coverage-conflict" aria-label="Resolve map changes"><h2>Choose the map to keep</h2><p>Saved districts: {latest.districts.length ? latest.districts.map(slug => DISTRICTS.find(d => d.slug === slug)!.name).join(", ") : "None"}.</p><p>Your selections: {selected.length ? selected.map(slug => DISTRICTS.find(d => d.slug === slug)!.name).join(", ") : "None"}.</p><div className="account-actions"><button className="account-secondary" onClick={() => { setCoverage(latest); setSelected(latest.districts); setLatest(null); setNeedsReview(false); setError(""); setNotice("Loaded the saved map."); }}>Use saved map</button><button className="account-secondary" onClick={() => { setCoverage(latest); setLatest(null); setNeedsReview(false); setError(""); setNotice("Your selections are ready. Choose Save map to replace the saved map."); }}>Keep my selections</button></div></section>}
    <div className="coverage-grid">
      <div className="coverage-map" aria-busy={busy}><SriLankaMap visited={selected} onDistrictClick={busy ? undefined : toggle} /></div>
      <section><div className="coverage-score"><strong>{selected.length}<span> / 25 districts</span></strong><span>{Math.round(selected.length / 25 * 100)}% by district count</span></div><p className="coverage-help">Choose districts on the map or use the district list below. Changes are private and are saved only when you choose Save map.</p>
        <div className="coverage-save"><button className="primary" disabled={busy || !dirty || needsReview} onClick={() => void save()}>{busy ? "Please wait…" : "Save map"}</button><p role="status">{notice || (dirty ? "Unsaved changes" : "No unsaved changes")}</p></div>
        <div className="coverage-provinces">{PROVINCES.map(province => <fieldset key={province} disabled={busy}><legend>{province}</legend>{DISTRICTS.filter(d => d.province === province).map(d => <label key={d.slug}><input type="checkbox" checked={selected.includes(d.slug)} onChange={() => toggle(d.slug)} />{d.name}</label>)}</fieldset>)}</div>
        {clear ? <div className="coverage-clear"><p>Clear all visited districts from your selections? Choose Save map afterwards to save the empty map.</p><div className="account-actions"><button className="account-secondary" onClick={() => setClear(false)}>Keep districts</button><button className="account-secondary" onClick={() => { setSelected([]); setClear(false); setNotice(""); }}>Clear selections</button></div></div> : <button className="account-secondary" disabled={busy || !selected.length} onClick={() => setClear(true)}>Clear all districts</button>}
      </section>
    </div>
  </>;
}
