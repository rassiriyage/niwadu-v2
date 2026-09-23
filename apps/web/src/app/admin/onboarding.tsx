"use client";

import Image from "next/image";
import { useCallback, useEffect, useRef, useState, type FormEvent, type RefObject } from "react";
import { useRouter } from "next/navigation";
import { api, type Hotel } from "@/lib/admin-api";
import { Staff } from "./hotel-profile";

type Room = { name: string; occupancy: number | null; quantity: number | null; rate: number | null };
type Fields = { name: string; city: string | null; country: string; address: string | null; contact_email: string | null; phone: string | null; description: string | null; property_type: string | null; amenities: string[]; rooms: Room[]; inventory_request: string | null; check_in: string | null; check_out: string | null; cancellation_policy: string | null; guest_rules: string | null };
type Draft = { hotel_id: number; step: number; version: number; fields: Fields; missing: string[]; can_publish: boolean; launch_requirements: string[] };
type LeaveRef = RefObject<(() => Promise<void>) | null>;
const steps = ["Hotel basics", "Listing & photos", "Room types", "Rates & availability", "Policies", "Hotel staff", "Review"];
const amenities = { wifi: "Wi-Fi", parking: "Parking", pool: "Swimming pool", restaurant: "Restaurant", air_conditioning: "Air conditioning", beach_access: "Beach access", airport_transfer: "Airport transfer", accessible_rooms: "Accessible rooms" };

export default function Onboarding({ id, beforeLeaveRef }: { id: number; beforeLeaveRef: LeaveRef }) {
  const [loaded, setLoaded] = useState<{ draft: Draft; hotel: Hotel }>();
  const [error, setError] = useState("");
  useEffect(() => {
    let active = true;
    Promise.all([api<Draft>(`hotels/${id}/onboarding`), api<{ data: Hotel }>(`hotels/${id}`)])
      .then(([draft, hotel]) => { if (active) setLoaded({ draft, hotel: hotel.data }); })
      .catch(e => { if (active) setError(e.message); });
    return () => { active = false; };
  }, [id]);
  if (!loaded) return <p role={error ? "alert" : "status"}>{error || "Opening hotel setup…"}</p>;
  return <Wizard initial={loaded.draft} hotel={loaded.hotel} beforeLeaveRef={beforeLeaveRef} />;
}

function Wizard({ initial, hotel, beforeLeaveRef }: { initial: Draft; hotel: Hotel; beforeLeaveRef: LeaveRef }) {
  const [fields, setFields] = useState(initial.fields);
  const [step, setStep] = useState(initial.step);
  const [review, setReview] = useState(initial);
  const [status, setStatus] = useState("All changes saved");
  const [error, setError] = useState("");
  const [moving, setMoving] = useState(false);
  const version = useRef(initial.version);
  const pending = useRef<Partial<Fields>>({});
  const running = useRef<Promise<void> | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const router = useRouter();
  const path = `hotels/${hotel.id}/onboarding`;

  const flush = useCallback(async (): Promise<void> => {
    if (timer.current) clearTimeout(timer.current);
    if (running.current) await running.current;
    if (!Object.keys(pending.current).length) return;
    const save = async () => {
      while (Object.keys(pending.current).length) {
        const patch = pending.current;
        pending.current = {};
        setStatus("Saving…"); setError("");
        try {
          const result = await api<Draft>(path, "PATCH", { version: version.current, fields: patch });
          version.current = result.version; setReview(result);
        } catch (e) {
          pending.current = { ...patch, ...pending.current };
          setStatus("Changes not saved"); setError((e as Error).message);
          throw e;
        }
      }
      setStatus("All changes saved");
    };
    running.current = save();
    try { await running.current; } finally { running.current = null; }
  }, [path]);

  useEffect(() => {
    beforeLeaveRef.current = flush;
    function warn(event: BeforeUnloadEvent) {
      if (Object.keys(pending.current).length || running.current) event.preventDefault();
    }
    function follow(event: MouseEvent) {
      const link = (event.target as Element).closest?.("a[href]") as HTMLAnchorElement | null;
      if (!link || link.target === "_blank" || event.ctrlKey || event.metaKey || event.button !== 0) return;
      if (Object.keys(pending.current).length || running.current) {
        event.preventDefault(); event.stopPropagation();
        void flush().then(() => { window.location.assign(link.href); }).catch(() => {});
      }
    }
    window.addEventListener("beforeunload", warn);
    document.addEventListener("click", follow, true);
    return () => {
      beforeLeaveRef.current = null;
      if (timer.current) clearTimeout(timer.current);
      window.removeEventListener("beforeunload", warn);
      document.removeEventListener("click", follow, true);
    };
  }, [beforeLeaveRef, flush]);

  function change<K extends keyof Fields>(key: K, value: Fields[K]) {
    setFields(current => ({ ...current, [key]: value }));
    pending.current = { ...pending.current, [key]: value };
    setStatus("Changes waiting to save");
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => { void flush().catch(() => {}); }, 800);
  }
  async function navigate(next?: number) {
    setMoving(true);
    try {
      await flush();
      if (next === undefined) { router.push(`/admin/hotels/${hotel.id}`); return; }
      const result = await api<Draft>(path, "PATCH", { version: version.current, step: next });
      version.current = result.version; setReview(result); setStep(next); setError("");
      requestAnimationFrame(() => heading.current?.focus());
    } catch (e) { setError((e as Error).message); setMoving(false); }
    finally { if (next !== undefined) setMoving(false); }
  }
  function text(key: keyof Fields, label: string, options: { type?: string; max?: number; multiline?: boolean; hint?: string } = {}) {
    const value = fields[key] as string | null;
    return <label>{label}{options.multiline ? <textarea value={value || ""} rows={5} maxLength={options.max || 5000} onChange={e => change(key, e.target.value)} /> : <input type={options.type || "text"} value={value || ""} maxLength={options.max || 255} onChange={e => change(key, e.target.value)} />}{options.hint && <span className="field-hint">{options.hint}</span>}</label>;
  }
  function roomChange(index: number, key: keyof Room, value: string) {
    change("rooms", fields.rooms.map((room, i) => i === index ? { ...room, [key]: key === "name" ? value : value === "" ? null : Number(value) } : room));
  }

  return <>
    <div className="page-heading setup-heading"><div><p className="eyebrow">HOTEL SETUP · STEP {step} OF 7</p><h1>{fields.name}</h1><p>Your progress is saved automatically. You can return to any step.</p></div><button className="secondary" disabled={moving} onClick={() => navigate()}>Save and exit</button></div>
    <p className="notice">Private draft · This hotel is not published and cannot receive bookings.</p>
    <nav className="setup-steps" aria-label="Hotel setup steps">{steps.map((label, index) => <button key={label} className={step === index + 1 ? "current" : "secondary"} aria-current={step === index + 1 ? "step" : undefined} disabled={moving} onClick={() => navigate(index + 1)}><span>{index + 1}</span>{label}</button>)}</nav>
    <div className="save-status"><span role="status">{status}</span>{error && <button className="secondary" onClick={() => { void flush().catch(() => {}); }}>Retry save</button>}</div>
    {error && <p className="error" role="alert">{error}</p>}
    <section className="panel wizard-panel"><h2 ref={heading} tabIndex={-1}>{steps[step - 1]}</h2>
      <fieldset disabled={moving} className="wizard-fields">
      {step === 1 && <><p>Start with the details a guest needs to find and contact the hotel.</p><div className="form-grid">
        {text("name", "Hotel name")}
        <label>Property type<select aria-label="Property type" value={fields.property_type || ""} onChange={e => change("property_type", e.target.value || null)}><option value="">Choose a property type</option>{Object.entries({ hotel: "Hotel", villa: "Villa", guest_house: "Guest house", resort: "Resort", apartment: "Apartment", hostel: "Hostel" }).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        {text("city", "City or destination", { hint: "For example, Galle or Ella." })}<label>Country<select aria-label="Country" value={fields.country} onChange={e => change("country", e.target.value)}>{Array.from(new Set([fields.country, "LK", "IN", "MV", "TH", "ID", "MY", "AE"])).map(code => <option value={code} key={code}>{new Intl.DisplayNames(["en"], { type: "region" }).of(code)}</option>)}</select></label>
        {text("address", "Street address", { max: 500 })}{text("contact_email", "Hotel contact email", { type: "email" })}{text("phone", "Phone number", { type: "tel", max: 40 })}
      </div></>}
      {step === 2 && <><p>Describe the real experience. Include only facilities the hotel offers.</p>{text("description", "About the hotel", { multiline: true, max: 10000 })}<fieldset className="amenities"><legend>Amenities</legend>{Object.entries(amenities).map(([value, label]) => <label key={value}><input type="checkbox" checked={fields.amenities.includes(value)} onChange={e => change("amenities", e.target.checked ? [...fields.amenities, value] : fields.amenities.filter(a => a !== value))} />{label}</label>)}</fieldset><Photos hotelId={hotel.id} /></>}
      {step === 3 && <><p>Add each kind of room once. Quantity is the number of rooms of that type.</p>{fields.rooms.map((room, index) => <div className="room-draft" key={index}><h3>Room type {index + 1}</h3><div className="form-grid"><label>Room type name<input value={room.name} maxLength={255} onChange={e => roomChange(index, "name", e.target.value)} /></label><label>Maximum guests<input type="number" min={1} max={30} value={room.occupancy ?? ""} onChange={e => roomChange(index, "occupancy", e.target.value)} /></label><label>Number of rooms<input type="number" min={1} max={10000} value={room.quantity ?? ""} onChange={e => roomChange(index, "quantity", e.target.value)} /></label></div><div className="room-actions"><button className="secondary" disabled={fields.rooms.length >= 50} onClick={() => change("rooms", [...fields.rooms, { ...room, name: `${room.name} (copy)` }])}>Duplicate room type</button><button className="secondary" onClick={() => change("rooms", fields.rooms.filter((_, i) => i !== index))}>Remove room type</button></div></div>)}<button className="secondary" disabled={fields.rooms.length >= 50} onClick={() => change("rooms", [...fields.rooms, { name: "", occupancy: 2, quantity: 1, rate: null }])}>Add room type</button><p className="hint">Add room photos under Listing &amp; photos and name the room in the caption.</p></>}
      {step === 4 && <><p>Tell the Niwadu team how the hotel expects to maintain availability.</p><label>Availability setup<select aria-label="Availability setup" value={fields.inventory_request || ""} onChange={e => change("inventory_request", e.target.value || null)}><option value="">Choose an option</option><option value="manual">Manage availability in Niwadu</option><option value="pms">Connect a hotel management system</option><option value="undecided">I need help deciding</option></select></label>{fields.inventory_request === "pms" && <p className="notice">The Niwadu team will arrange the connection. No technical configuration is needed here.</p>}{fields.inventory_request === "manual" && <><p>Record an indicative nightly rate in LKR for each room type. Final rate plans and dated availability are set up separately.</p>{fields.rooms.length === 0 && <p>Add room types in step 3 first.</p>}{fields.rooms.map((room, index) => <label className="rate-field" key={index}>{room.name || `Room type ${index + 1}`} · nightly rate (LKR)<input type="number" min={0} max={100000000} step="0.01" value={room.rate ?? ""} onChange={e => roomChange(index, "rate", e.target.value)} /></label>)}</>}<p className="hint">This records a setup preference. It does not activate a connection or make rooms available for sale.</p></>}
      {step === 5 && <><p>Use the hotel’s actual policies. Do not guess terms that have not been agreed.</p><div className="form-grid">{text("check_in", "Check-in from", { type: "time" })}{text("check_out", "Check-out by", { type: "time" })}</div>{text("cancellation_policy", "Cancellation policy", { multiline: true, hint: "Explain cancellation deadlines and any charges." })}{text("guest_rules", "Guest rules (optional)", { multiline: true, hint: "For example, smoking, pets and quiet hours." })}</>}
      {step === 6 && <><p>Assign a hotel manager who will look after this property. Existing accounts keep their password.</p><Staff hotel={hotel} title="People with access" /></>}
      {step === 7 && <><p>Check the information below. Saving a draft does not publish it.</p><dl className="review-details"><dt>Hotel</dt><dd>{fields.name} · {fields.property_type || "Property type missing"}</dd><dt>Location</dt><dd>{[fields.address, fields.city, fields.country].filter(Boolean).join(", ")}</dd><dt>Contact</dt><dd>{fields.contact_email || "Email missing"} · {fields.phone || "Phone missing"}</dd><dt>Description</dt><dd>{fields.description || "Description missing"}</dd><dt>Room types</dt><dd>{fields.rooms.length ? fields.rooms.map(r => `${r.name || "Unnamed room"}: ${r.quantity ?? "?"} rooms, up to ${r.occupancy ?? "?"} guests`).join("; ") : "No rooms added"}</dd><dt>Availability</dt><dd>{fields.inventory_request === "manual" ? "Manual setup requested" : fields.inventory_request === "pms" ? "Hotel system connection requested" : "Not decided"}</dd><dt>Check-in / check-out</dt><dd>{fields.check_in || "Missing"} / {fields.check_out || "Missing"}</dd><dt>Cancellation</dt><dd>{fields.cancellation_policy || "Policy missing"}</dd></dl><h3>{review.missing.length ? "Still needed in this draft" : "Draft details recorded"}</h3>{review.missing.length > 0 && <ul>{review.missing.map(item => <li key={item}>{item}</li>)}</ul>}<div className="notice"><strong>Not ready to publish</strong><p>The Niwadu team must complete these checks before the hotel can receive bookings:</p><ul>{review.launch_requirements.map(item => <li key={item}>{item}</li>)}</ul></div><button disabled>Publish unavailable</button></>}
      </fieldset>
    </section>
    <div className="wizard-controls"><button className="secondary" disabled={moving || step === 1} onClick={() => navigate(step - 1)}>Back</button><span>Step {step} of 7</span><button disabled={moving} onClick={() => navigate(step === 7 ? undefined : step + 1)}>{moving ? "Saving…" : step === 7 ? "Save and finish later" : "Save and continue"}</button></div>
  </>;
}

type Photo = { id: number; caption: string };
function Photos({ hotelId }: { hotelId: number }) {
  const [photos, setPhotos] = useState<Photo[]>([]);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [removing, setRemoving] = useState<number | null>(null);
  const path = `hotels/${hotelId}/photos`;
  useEffect(() => { let active = true; api<{ data: Photo[] }>(path).then(r => { if (active) setPhotos(r.data); }).catch(e => { if (active) setError(e.message); }); return () => { active = false; }; }, [path]);
  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = event.currentTarget; setBusy(true); setError("");
    try { const result = await api<{ data: Photo }>(path, "POST", new FormData(form)); setPhotos(current => [...current, result.data]); form.reset(); }
    catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  }
  async function remove(id: number) {
    setBusy(true); setError("");
    try { await api(`${path}/${id}`, "DELETE"); setPhotos(current => current.filter(p => p.id !== id)); setRemoving(null); }
    catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  }
  return <div className="photos"><h3>Hotel &amp; room photos</h3><p>Upload JPG, PNG or WebP files, up to 5 MB each. Photos remain private while this hotel is a draft.</p><div className="photo-grid">{photos.map(photo => <figure key={photo.id}><Image src={`/api/v1/${path}/${photo.id}`} alt={photo.caption} width={320} height={200} unoptimized /><figcaption>{photo.caption}</figcaption>{removing === photo.id ? <><button className="danger" disabled={busy} onClick={() => remove(photo.id)}>Confirm delete</button><button className="secondary" onClick={() => setRemoving(null)}>Keep photo</button></> : <button className="secondary" disabled={busy} onClick={() => setRemoving(photo.id)}>Remove photo</button>}</figure>)}</div><form onSubmit={upload} className="form-grid"><label>Photo<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required disabled={busy} /></label><label>Photo caption<input name="caption" required maxLength={255} disabled={busy} placeholder="For example, Deluxe room balcony" /></label><div className="full"><button disabled={busy || photos.length >= 50}>{busy ? "Uploading…" : "Upload photo"}</button></div></form>{error && <p className="error" role="alert">{error}</p>}</div>;
}
