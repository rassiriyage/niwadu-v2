"use client";

import Image from "next/image";
import { useCallback, useEffect, useRef, useState, type FormEvent, type RefObject } from "react";
import { useRouter } from "next/navigation";
import { api, ApiError, type Hotel } from "@/lib/admin-api";
import { Staff } from "./hotel-profile";
import { readRecovery, writeRecovery, recoveryKey, type Fields, type Room, type Recovery } from "./onboarding-recovery";

type Draft = { hotel_id: number; step: number; version: number; fields: Fields; missing: string[]; can_publish: boolean; launch_requirements: string[] };
type LeaveRef = RefObject<(() => Promise<void>) | null>;
const steps = ["Hotel basics", "Listing & photos", "Room types", "Rates & availability", "Policies", "Hotel staff", "Review"];
const amenities = { wifi: "Wi-Fi", parking: "Parking", pool: "Swimming pool", restaurant: "Restaurant", air_conditioning: "Air conditioning", beach_access: "Beach access", airport_transfer: "Airport transfer", accessible_rooms: "Accessible rooms" };

export default function Onboarding({ id, userId, beforeLeaveRef }: { id: number; userId: number; beforeLeaveRef: LeaveRef }) {
  const [loaded, setLoaded] = useState<{ draft: Draft; hotel: Hotel; recovery?: Recovery }>();
  const [error, setError] = useState("");
  useEffect(() => {
    let active = true;
    Promise.all([api<Draft>(`hotels/${id}/onboarding`), api<{ data: Hotel }>(`hotels/${id}`)])
      .then(([draft, hotel]) => { if (active) setLoaded({ draft, hotel: hotel.data, recovery: readRecovery(recoveryKey(userId, id)) }); })
      .catch(e => { if (active) setError(e.message); });
    return () => { active = false; };
  }, [id, userId]);
  if (!loaded) return <p role={error ? "alert" : "status"}>{error || "Opening hotel setup…"}</p>;
  return <Wizard initial={loaded.draft} recovered={loaded.recovery} storageKey={recoveryKey(userId, id)} hotel={loaded.hotel} beforeLeaveRef={beforeLeaveRef} />;
}

function Wizard({ initial, hotel, beforeLeaveRef, recovered, storageKey }: { initial: Draft; hotel: Hotel; beforeLeaveRef: LeaveRef; recovered?: Recovery; storageKey: string }) {
  const recoveredPatch: Partial<Fields> = recovered ? Object.fromEntries(recovered.pendingKeys.map(key => [key, recovered.fields[key]])) : {};
  const recoveredConflict = recovered && recovered.version !== initial.version ? new ApiError("This draft changed in another window.", 409) : null;
  const [fields, setFields] = useState(recovered?.fields ?? initial.fields);
  const [step, setStep] = useState(recovered?.step ?? initial.step);
  const [review, setReview] = useState(initial);
  const [status, setStatus] = useState(recovered ? "Recovered unsaved changes. Review them before saving." : "All changes saved");
  const [error, setError] = useState<Error | null>(recoveredConflict);
  const [unsaved, setUnsaved] = useState<Partial<Fields>>(recoveredPatch);
  const [copyStatus, setCopyStatus] = useState("");
  const conflict = useRef<ApiError | null>(recoveredConflict);
  const summary = useRef<HTMLDivElement>(null);
  const recoveryText = useRef<HTMLTextAreaElement>(null);
  const fieldErrors = error instanceof ApiError ? error.fieldErrors : {};
  const isConflict = error instanceof ApiError && error.status === 409;
  const retryable = error && (!(error instanceof ApiError) || error.status >= 500 || error.status === 408 || error.status === 429);
  const [moving, setMoving] = useState(false);
  const [retryStep, setRetryStep] = useState<number | null>();
  const version = useRef(recovered?.version ?? initial.version);
  const pending = useRef<Partial<Fields>>(recoveredPatch);
  const currentFields = useRef(recovered?.fields ?? initial.fields);
  const currentStep = useRef(recovered?.step ?? initial.step);
  const inFlight = useRef<Partial<Fields>>({});
  const active = useRef(true);
  const [storageError, setStorageError] = useState("");
  const running = useRef<Promise<void> | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const router = useRouter();
  const path = `hotels/${hotel.id}/onboarding`;

  const persist = useCallback(() => {
    if (!active.current) return;
    const pendingKeys = Object.keys({ ...inFlight.current, ...pending.current }) as (keyof Fields)[];
    const saved = pendingKeys.length ? { version: version.current, step: currentStep.current, fields: currentFields.current, pendingKeys } : undefined;
    setStorageError(writeRecovery(storageKey, saved) ? "" : "This browser could not keep a recovery copy. Keep this tab open and copy your unsaved values before leaving.");
  }, [storageKey]);

  const flush = useCallback(async (): Promise<void> => {
    if (timer.current) clearTimeout(timer.current);
    if (running.current) await running.current;
    if (conflict.current) throw conflict.current;
    if (!Object.keys(pending.current).length) return;
    const save = async () => {
      while (active.current && Object.keys(pending.current).length) {
        const patch = pending.current;
        inFlight.current = patch; pending.current = {};
        setStatus("Saving…");
        try {
          const result = await api<Draft>(path, "PATCH", { version: version.current, fields: patch });
          version.current = result.version; inFlight.current = {}; persist(); setReview(result); setError(null);
        } catch (e) {
          pending.current = { ...patch, ...pending.current }; inFlight.current = {}; persist();
          if (e instanceof ApiError && e.status === 409) {
            conflict.current = e;
            setUnsaved({ ...pending.current });
          }
          setStatus("Changes not saved"); setError(e as Error);
          throw e;
        }
      }
      setStatus("All changes saved");
    };
    running.current = save();
    try { await running.current; } finally { running.current = null; }
  }, [path, persist]);

  useEffect(() => {
    active.current = true;
    beforeLeaveRef.current = flush;
    function warn(event: BeforeUnloadEvent) {
      if (Object.keys(pending.current).length || running.current) event.preventDefault();
    }
    function follow(event: MouseEvent) {
      const link = (event.target as Element).closest?.("a[href]") as HTMLAnchorElement | null;
      if (!link || link.getAttribute("href")?.startsWith("#") || link.target === "_blank" || event.ctrlKey || event.metaKey || event.button !== 0) return;
      if (Object.keys(pending.current).length || running.current) {
        event.preventDefault(); event.stopPropagation();
        void flush().then(() => { window.location.assign(link.href); }).catch(() => {});
      }
    }
    window.addEventListener("beforeunload", warn);
    document.addEventListener("click", follow, true);
    return () => {
      active.current = false;
      beforeLeaveRef.current = null;
      if (timer.current) clearTimeout(timer.current);
      window.removeEventListener("beforeunload", warn);
      document.removeEventListener("click", follow, true);
    };
  }, [beforeLeaveRef, flush]);

  function change<K extends keyof Fields>(key: K, value: Fields[K]) {
    currentFields.current = { ...currentFields.current, [key]: value };
    setFields(currentFields.current);
    pending.current = { ...pending.current, [key]: value }; persist();
    if (conflict.current) {
      setUnsaved({ ...pending.current }); setCopyStatus("");
      return;
    }
    setRetryStep(undefined);
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
      version.current = result.version; setReview(result); setStep(next); currentStep.current = next; setError(null); setRetryStep(undefined); setStatus("All changes saved");
      requestAnimationFrame(() => heading.current?.focus());
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        conflict.current = e; setUnsaved({ ...pending.current });
      }
      setError(e as Error); setRetryStep(next ?? null); setStatus("Changes not saved"); setMoving(false);
      requestAnimationFrame(() => summary.current?.focus());
    }
    finally { if (next !== undefined) setMoving(false); }
  }
  async function reloadLatest() {
    if (timer.current) clearTimeout(timer.current);
    setMoving(true);
    try {
      const latest = await api<Draft>(path);
      version.current = latest.version; pending.current = {}; conflict.current = null;
      currentFields.current = latest.fields; currentStep.current = latest.step; inFlight.current = {}; persist();
      setFields(latest.fields); setReview(latest); setStep(latest.step);
      setError(null); setRetryStep(undefined); setUnsaved({}); setCopyStatus(""); setStatus("All changes saved");
      requestAnimationFrame(() => heading.current?.focus());
    } catch {
      setCopyStatus("Could not load the latest draft. Your unsaved changes are still here. Try reloading latest again.");
    } finally { setMoving(false); }
  }
  async function copyUnsaved() {
    try {
      await navigator.clipboard.writeText(JSON.stringify(unsaved, null, 2));
      setCopyStatus("Unsaved changes copied.");
    } catch {
      recoveryText.current?.focus(); recoveryText.current?.select();
      setCopyStatus("Copy was unavailable. The unsaved changes are selected below; use your device’s copy command.");
    }
  }
  function fieldProps(key: string) {
    return { id: `setup-${key}`, "aria-invalid": fieldErrors[`fields.${key}`] ? true : undefined, "aria-describedby": fieldErrors[`fields.${key}`] ? `error-${key}` : undefined };
  }
  function fieldError(key: string) {
    return fieldErrors[`fields.${key}`] && <span className="field-error" id={`error-${key}`}>{fieldErrors[`fields.${key}`].join(" ")}</span>;
  }
  function focusField(key: string) {
    const field = key.replace(/^fields\./, "");
    const root = field.split(".")[0];
    const targetStep = root === "rooms" ? (field.endsWith(".rate") ? 4 : 3) : ["description", "amenities"].includes(root) ? 2 : root === "inventory_request" ? 4 : ["check_in", "check_out", "cancellation_policy", "guest_rules"].includes(root) ? 5 : 1;
    setStep(targetStep); currentStep.current = targetStep; persist();
    requestAnimationFrame(() => document.getElementById(`setup-${field}`)?.focus());
  }
  function text(key: keyof Fields, label: string, options: { type?: string; max?: number; multiline?: boolean; hint?: string } = {}) {
    const value = fields[key] as string | null;
    const props = fieldProps(key);
    return <label htmlFor={props.id}>{label}{options.multiline ? <textarea {...props} aria-label={label} value={value || ""} rows={5} maxLength={options.max || 5000} onChange={e => change(key, e.target.value)} /> : <input {...props} aria-label={label} type={options.type || "text"} value={value || ""} maxLength={options.max || 255} onChange={e => change(key, e.target.value)} />}{fieldError(key)}{options.hint && <span className="field-hint">{options.hint}</span>}</label>;
  }
  function roomInput(room: Room, index: number, key: keyof Room, label: string, min?: number, max?: number) {
    const field = `rooms.${index}.${key}`;
    return <label htmlFor={`setup-${field}`}>{label}<input {...fieldProps(field)} aria-label={label} type={key === "name" ? "text" : "number"} min={min} max={max} maxLength={key === "name" ? 255 : undefined} step={key === "rate" ? "0.01" : undefined} value={room[key] ?? ""} onChange={e => roomChange(index, key, e.target.value)} />{fieldError(field)}</label>;
  }
  function roomChange(index: number, key: keyof Room, value: string) {
    change("rooms", fields.rooms.map((room, i) => i === index ? { ...room, [key]: key === "name" ? value : value === "" ? null : Number(value) } : room));
  }

  return <>
    <div className="page-heading setup-heading"><div><p className="eyebrow">HOTEL SETUP · STEP {step} OF 7</p><h1>{fields.name}</h1><p>Your progress is saved automatically. You can return to any step.</p></div><button className="secondary" disabled={moving} onClick={() => navigate()}>Save and exit</button></div>
    <p className="notice">Private draft · This hotel is not published and cannot receive bookings.</p>
    <nav className="setup-steps" aria-label="Hotel setup steps">{steps.map((label, index) => <button key={label} className={step === index + 1 ? "current" : "secondary"} aria-current={step === index + 1 ? "step" : undefined} disabled={moving} onClick={() => navigate(index + 1)}><span>{index + 1}</span>{label}</button>)}</nav>
    <div className="save-status"><span role="status">{status}</span>{retryable && <button className="secondary" disabled={moving} onClick={() => { if (retryStep !== undefined) void navigate(retryStep ?? undefined); else void flush().catch(() => {}); }}>Retry save</button>}</div>
    {error && <div className="error" ref={summary} tabIndex={-1} role="region" aria-label={Object.keys(fieldErrors).length ? "Check these fields" : "Save problem"}>
      <p role="alert">{isConflict ? "This draft changed in another window. Your unsaved changes are preserved here." : Object.keys(fieldErrors).length ? "Check these fields before continuing." : error.message}</p>
      {Object.keys(fieldErrors).length > 0 && <ul>{Object.entries(fieldErrors).map(([key, messages]) => <li key={key}><a href={`#setup-${key.replace(/^fields\./, "")}`} onClick={event => { event.preventDefault(); focusField(key); }}>{key.match(/^fields\.rooms\.(\d+)\./) ? `Room type ${Number(key.split(".")[2]) + 1}: ` : ""}{messages.join(" ")}</a></li>)}</ul>}
      {isConflict && <>
        <p>Reloading will discard your unsaved changes in this window.</p>
        <p>Copy them first if you want to keep them. You can re-enter them after reviewing the latest draft.</p>
        <div className="room-actions"><button className="secondary" disabled={moving} onClick={copyUnsaved}>Copy unsaved changes</button><button disabled={moving} onClick={reloadLatest}>Discard unsaved changes and reload latest</button></div>
        <label htmlFor="unsaved-changes">Unsaved changes (JSON)<textarea id="unsaved-changes" ref={recoveryText} readOnly rows={5} value={JSON.stringify(unsaved, null, 2)} /></label>
        <p role="status">{copyStatus}</p>
      </>}
    </div>}
    {storageError && <p className="error" role="alert">{storageError}</p>}
    <section className="panel wizard-panel"><h2 ref={heading} tabIndex={-1}>{steps[step - 1]}</h2>
      <fieldset disabled={moving} className="wizard-fields">
      {step === 1 && <><p>Start with the details a guest needs to find and contact the hotel.</p><div className="form-grid">
        {text("name", "Hotel name")}
        <label htmlFor="setup-property_type">Property type<select {...fieldProps("property_type")} aria-label="Property type" value={fields.property_type || ""} onChange={e => change("property_type", e.target.value || null)}><option value="">Choose a property type</option>{Object.entries({ hotel: "Hotel", villa: "Villa", guest_house: "Guest house", resort: "Resort", apartment: "Apartment", hostel: "Hostel" }).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>{fieldError("property_type")}</label>
        {text("city", "City or destination", { hint: "For example, Galle or Ella." })}<label htmlFor="setup-country">Country<select {...fieldProps("country")} aria-label="Country" value={fields.country} onChange={e => change("country", e.target.value)}>{Array.from(new Set([fields.country, "LK", "IN", "MV", "TH", "ID", "MY", "AE"])).map(code => <option value={code} key={code}>{new Intl.DisplayNames(["en"], { type: "region" }).of(code)}</option>)}</select>{fieldError("country")}</label>
        {text("address", "Street address", { max: 500 })}{text("contact_email", "Hotel contact email", { type: "email" })}{text("phone", "Phone number", { type: "tel", max: 40 })}
      </div></>}
      {step === 2 && <><p>Describe the real experience. Include only facilities the hotel offers.</p>{text("description", "About the hotel", { multiline: true, max: 10000 })}<fieldset className="amenities" {...fieldProps("amenities")} tabIndex={-1}><legend>Amenities</legend>{fieldError("amenities")}{Object.entries(amenities).map(([value, label]) => <label key={value}><input type="checkbox" checked={fields.amenities.includes(value)} onChange={e => change("amenities", e.target.checked ? [...fields.amenities, value] : fields.amenities.filter(a => a !== value))} />{label}</label>)}</fieldset><Photos hotelId={hotel.id} /></>}
      {step === 3 && <div {...fieldProps("rooms")} tabIndex={-1}>{fieldError("rooms")}<p>Add each kind of room once. Quantity is the number of rooms of that type.</p>{fields.rooms.map((room, index) => <div className="room-draft" key={index}><h3>Room type {index + 1}</h3><div className="form-grid">{roomInput(room, index, "name", "Room type name")}{roomInput(room, index, "occupancy", "Maximum guests", 1, 30)}{roomInput(room, index, "quantity", "Number of rooms", 1, 10000)}</div><div className="room-actions"><button className="secondary" disabled={fields.rooms.length >= 50} onClick={() => change("rooms", [...fields.rooms, { ...room, name: `${room.name} (copy)` }])}>Duplicate room type</button><button className="secondary" onClick={() => change("rooms", fields.rooms.filter((_, i) => i !== index))}>Remove room type</button></div></div>)}<button className="secondary" disabled={fields.rooms.length >= 50} onClick={() => change("rooms", [...fields.rooms, { name: "", occupancy: 2, quantity: 1, rate: null }])}>Add room type</button><p className="hint">Add room photos under Listing &amp; photos and name the room in the caption.</p></div>}
      {step === 4 && <><p>Tell the Niwadu team how the hotel expects to maintain availability.</p><label htmlFor="setup-inventory_request">Availability setup<select {...fieldProps("inventory_request")} aria-label="Availability setup" value={fields.inventory_request || ""} onChange={e => change("inventory_request", e.target.value || null)}><option value="">Choose an option</option><option value="manual">Manage availability in Niwadu</option><option value="pms">Connect a hotel management system</option><option value="undecided">I need help deciding</option></select>{fieldError("inventory_request")}</label>{fields.inventory_request === "pms" && <p className="notice">The Niwadu team will arrange the connection. No technical configuration is needed here.</p>}{(fields.inventory_request === "manual" || Object.keys(fieldErrors).some(key => key.endsWith(".rate"))) && <><p>Record an indicative nightly rate in LKR for each room type. Final rate plans and dated availability are set up separately.</p>{fields.rooms.length === 0 && <p>Add room types in step 3 first.</p>}{fields.rooms.map((room, index) => <div className="rate-field" key={index}>{roomInput(room, index, "rate", `${room.name || `Room type ${index + 1}`} · nightly rate (LKR)`, 0, 100000000)}</div>)}</>}<p className="hint">This records a setup preference. It does not activate a connection or make rooms available for sale.</p></>}
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
