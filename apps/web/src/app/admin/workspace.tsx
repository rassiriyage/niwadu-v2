"use client";

import Link from "next/link";
import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { api, type Hotel, type Session, type StaffUser } from "@/lib/admin-api";
import HotelProfile from "./hotel-profile";

export default function Workspace({ hotelId }: { hotelId?: number }) {
  const [user, setUser] = useState<StaffUser | null>();
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    let active = true;
    api<Session>("session").then(s => { if (active) setUser(s.user); }).catch(() => { if (active) setError("We cannot reach hotel management. Please refresh to try again."); });
    return () => { active = false; };
  }, []);

  async function signIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError("");
    const form = new FormData(event.currentTarget);
    try { const session = await api<Session>("login", "POST", Object.fromEntries(form)); setUser(session.user); }
    catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  async function signOut() {
    setBusy(true); setError("");
    try { await api("logout", "POST"); setUser(null); }
    catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }

  if (user === undefined) return <main className="signin"><p role="status">{error || "Opening hotel management…"}</p>{error && <a href="/admin">Try again</a>}</main>;
  if (!user) return <main className="signin">
    <Link className="wordmark" href="/admin">niwadu<span>hotel management</span></Link>
    <h1>Welcome back</h1><p>Sign in to manage your hotels and team.</p>
    <form onSubmit={signIn} className="stack">
      <label>Email address<input type="email" name="email" autoComplete="username" required /></label>
      <label>Password<input type="password" name="password" autoComplete="current-password" required /></label>
      {error && <p className="error" role="alert">{error}</p>}
      <button disabled={busy}>{busy ? "Signing in…" : "Sign in"}</button>
    </form>
    <p className="hint">New to Niwadu? Use the password-setup link sent by your administrator.</p>
  </main>;

  return <>
    <a className="skip-link" href="#main">Skip to content</a>
    <header className="admin-header"><Link className="wordmark" href="/admin">niwadu<span>hotel management</span></Link><div className="account"><span>{user.name}</span><button className="secondary" onClick={signOut} disabled={busy}>Sign out</button></div></header>
    <div className="admin-body"><aside><nav aria-label="Management"><Link className="nav-active" href="/admin">Hotels</Link></nav><p>{user.platform_role ? "Niwadu workspace" : "Your hotel workspace"}</p></aside>
    <main id="main">{error && <p role="alert" className="error">{error}</p>}{hotelId ? <HotelProfile key={hotelId} id={hotelId} /> : <HotelList user={user} />}</main></div>
  </>;
}

function HotelList({ user }: { user: StaffUser }) {
  const [hotels, setHotels] = useState<Hotel[]>();
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);
  const [busy, setBusy] = useState(false);
  const router = useRouter();
  useEffect(() => {
    let active = true;
    api<{ data: Hotel[]; meta: { last_page: number } }>(`hotels?page=${page}`).then(r => {
      if (active) { setHotels(r.data); setLastPage(r.meta.last_page); setError(""); }
    }).catch(e => { if (active) setError(e.message); });
    return () => { active = false; };
  }, [page]);

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError("");
    try {
      const hotel = await api<{ data: Hotel }>("hotels", "POST", Object.fromEntries(new FormData(event.currentTarget)));
      router.push(`/admin/hotels/${hotel.data.id}`);
    } catch (e) { setError((e as Error).message); setBusy(false); }
  }

  return <>
    <div className="page-heading"><div><p className="eyebrow">YOUR WORKSPACE</p><h1>Hotels</h1><p>Manage property details and the people who look after them.</p></div>{user.platform_role && <button onClick={() => setCreating(!creating)}>{creating ? "Close" : "Add hotel"}</button>}</div>
    {creating && <section className="panel"><h2>Start a hotel draft</h2><p>This creates a private record. It will not be published or accept bookings.</p><form className="form-grid" onSubmit={create}>
      <label>Hotel name<input name="name" required maxLength={255} /></label><label>City or destination<input name="city" maxLength={255} /></label><div className="full"><button disabled={busy}>{busy ? "Creating…" : "Create draft"}</button></div>
    </form></section>}
    {error && <p role="alert" className="error">{error}</p>}
    {!hotels && !error && <p role="status">Loading hotels…</p>}
    {hotels?.length === 0 && <section className="panel empty"><h2>No hotels yet</h2><p>{user.platform_role ? "Add your first hotel to get started." : "Your administrator will assign your hotel access here."}</p></section>}
    {!!hotels?.length && <section className="panel hotel-list" aria-label="Your hotels">{hotels.map(hotel => <Link className="hotel-row" key={hotel.id} href={`/admin/hotels/${hotel.id}`}><div><strong>{hotel.name}</strong><span>{hotel.city || "Location not added"} · {hotel.country}</span></div><div className="row-end"><span className="badge">{hotel.status}</span><span aria-hidden="true">→</span></div></Link>)}</section>}
    {lastPage > 1 && <nav className="pagination" aria-label="Hotel pages"><button className="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button><span>Page {page} of {lastPage}</span><button className="secondary" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>Next</button></nav>}
  </>;
}
