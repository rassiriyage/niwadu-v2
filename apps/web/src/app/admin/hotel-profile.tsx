"use client";

import Link from "next/link";
import { useEffect, useState, type FormEvent } from "react";
import { api, roles, type Hotel, type Member } from "@/lib/admin-api";

export default function HotelProfile({ id }: { id: number }) {
  const [hotel, setHotel] = useState<Hotel>();
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    let active = true;
    api<{ data: Hotel }>(`hotels/${id}`).then(r => { if (active) setHotel(r.data); }).catch(e => { if (active) setError(e.message); });
    return () => { active = false; };
  }, [id]);
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(""); setMessage("");
    try { const r = await api<{ data: Hotel }>(`hotels/${id}`, "PATCH", Object.fromEntries(new FormData(event.currentTarget))); setHotel(r.data); setMessage("Hotel details saved."); }
    catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  if (!hotel) return <><Link href="/admin">← Back to hotels</Link><p role={error ? "alert" : "status"}>{error || "Loading hotel…"}</p></>;
  return <>
    <Link className="back-link" href="/admin">← All hotels</Link>
    <div className="page-heading"><div><p className="eyebrow">PROPERTY DETAILS</p><h1>{hotel.name}</h1><p>{hotel.city || "Add the hotel's location"}</p></div><span className="badge">{hotel.status}</span></div>
    {hotel.status === "draft" && <p className="notice">Private draft · This hotel is not published and cannot receive bookings.</p>}
    <section className="panel"><h2>Hotel profile</h2><p>Keep the details your team needs in one place.</p>
      <form onSubmit={save} className="form-grid"><fieldset disabled={!hotel.permissions.edit_profile || busy} className="form-grid full">
        <label>Hotel name<input name="name" defaultValue={hotel.name} required maxLength={255} /></label>
        <label>City or destination<input name="city" defaultValue={hotel.city || ""} maxLength={255} /></label>
        <label className="full">Street address<input name="address" defaultValue={hotel.address || ""} maxLength={500} /></label>
        <label>Contact email<input type="email" name="contact_email" defaultValue={hotel.contact_email || ""} /></label>
        <label>Phone number<input type="tel" name="phone" defaultValue={hotel.phone || ""} maxLength={40} /></label>
        <label className="full">About the hotel<textarea name="description" defaultValue={hotel.description || ""} rows={5} maxLength={10000} /></label>
      </fieldset>
      <div className="full">{hotel.permissions.edit_profile ? <button disabled={busy}>{busy ? "Saving…" : "Save details"}</button> : <p className="hint">Your role has read-only access to this profile.</p>}</div>
      {error && <p className="error full" role="alert">{error}</p>}{message && <p className="success full" role="status">{message}</p>}
      </form>
    </section>
    {hotel.permissions.view_staff && <Staff hotel={hotel} />}
  </>;
}

function Staff({ hotel }: { hotel: Hotel }) {
  const [members, setMembers] = useState<Member[]>();
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [revoking, setRevoking] = useState<number | null>(null);
  const path = `hotels/${hotel.id}/staff`;
  useEffect(() => {
    let active = true;
    api<{ data: Member[] }>(path).then(r => { if (active) setMembers(r.data); }).catch(e => { if (active) setError(e.message); });
    return () => { active = false; };
  }, [path]);

  async function grant(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = event.currentTarget;
    setBusy(true); setError(""); setMessage("");
    try {
      await api(path, "POST", Object.fromEntries(new FormData(form)));
      form.reset();
      setMembers((await api<{ data: Member[] }>(path)).data);
      setMessage("Hotel access saved. New accounts receive a password-setup link.");
    } catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  async function sendPasswordLink(member: Member) {
    setBusy(true); setError(""); setMessage("");
    try { await api(`${path}/${member.id}/password-link`, "POST"); setMessage(`Password link sent to ${member.email}.`); }
    catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  async function revoke(member: Member) {
    setBusy(true); setError(""); setMessage("");
    try { await api(`${path}/${member.id}`, "DELETE"); setMembers(members?.filter(m => m.id !== member.id)); setRevoking(null); setMessage(`Access removed for ${member.name}.`); }
    catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  return <section className="panel"><h2>Hotel staff</h2><p>These people have access to {hotel.name}.</p>
    {members?.map(member => <div className="member-row" key={member.id}><div><strong>{member.name}</strong><span>{member.email}</span></div><span>{roles[member.role]}</span>
      {hotel.permissions.manage_staff && <button className="secondary" disabled={busy} onClick={() => sendPasswordLink(member)}>Send password link<span className="sr-only"> to {member.name}</span></button>}
      {hotel.permissions.manage_staff && (revoking === member.id ? <div className="revoke-confirm"><span>Remove this hotel access?</span><button className="danger" disabled={busy} onClick={() => revoke(member)}>Confirm removal</button><button className="secondary" onClick={() => setRevoking(null)}>Keep access</button></div> : <button className="secondary" onClick={() => setRevoking(member.id)}>Remove access<span className="sr-only"> for {member.name}</span></button>)}
    </div>)}
    {members?.length === 0 && <p className="hint">No staff assigned yet.</p>}
    {!members && !error && <p role="status">Loading staff…</p>}
    {hotel.permissions.manage_staff && <form onSubmit={grant} className="form-grid staff-form"><h3 className="full">Give someone access</h3>
      <label>Full name<input name="name" required maxLength={255} /></label><label>Email address<input type="email" name="email" required /></label>
      <label>Hotel role<select name="role" defaultValue="viewer">{Object.entries(roles).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
      <p className="hint">Access applies only to this hotel. Use the same email to update an existing staff member’s role.</p>
      <div className="full"><button disabled={busy}>{busy ? "Saving…" : "Grant hotel access"}</button></div>
    </form>}
    {error && <p role="alert" className="error">{error}</p>}{message && <p role="status" className="success">{message}</p>}
  </section>;
}
