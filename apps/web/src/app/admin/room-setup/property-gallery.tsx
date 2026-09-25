"use client";

import Image from "next/image";
import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { ApiError } from "@/lib/admin-api";
import { useOperations } from "../operations/forms";
import type { SaveRegistry } from "./draft-form";

type Photo = { id: number; caption: string };
type Gallery = { data: Photo[]; meta: { version: number } };

export default function PropertyGallery({ hotelId, registry }: { hotelId: number; registry: SaveRegistry }) {
  const request = useOperations(), path = `hotels/${hotelId}/photos`;
  const [gallery, setGallery] = useState<Gallery>(), [error, setError] = useState(""), [busy, setBusy] = useState(false), [blocked, setBlocked] = useState(false);
  const form = useRef<HTMLFormElement>(null), dirty = useRef(false), running = useRef(false);
  const load = useCallback(async () => { setGallery(await request<Gallery>(path)); }, [request, path]);
  useEffect(() => { void Promise.resolve().then(load).catch(e => setError(e.message)); }, [load]);
  useEffect(() => {
    registry.set(path, async () => { if (dirty.current || running.current) throw new Error("Upload or discard the selected property photo before continuing."); });
    const warn = (event: BeforeUnloadEvent) => { if (dirty.current || running.current) event.preventDefault(); };
    window.addEventListener("beforeunload", warn);
    return () => { registry.delete(path); window.removeEventListener("beforeunload", warn); };
  }, [registry, path]);
  async function mutate(method: string, data: object | FormData, suffix = "") {
    if (!gallery || running.current) return;
    running.current = true; setBusy(true); setError("");
    try {
      if (data instanceof FormData) data.set("version", String(gallery.meta.version));
      else data = { ...data, version: gallery.meta.version };
      await request(`${path}${suffix}`, method, data); await load();
      if (method === "POST") { form.current?.reset(); dirty.current = false; }
    } catch (e) { setError((e as Error).message); setBlocked(!(e instanceof ApiError) || e.status === 409 || e.status >= 500 || e.status === 408); }
    finally { running.current = false; setBusy(false); }
  }
  function upload(event: FormEvent<HTMLFormElement>) { event.preventDefault(); void mutate("POST", new FormData(event.currentTarget)); }
  async function reconcile() {
    if (dirty.current && !window.confirm("Discard the selected upload and inspect the saved property gallery?")) return;
    setBusy(true);
    try { await load(); setBlocked(false); setError(""); dirty.current = false; form.current?.reset(); }
    catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  function move(index: number, delta: number) { if (!gallery) return; const ids = gallery.data.map(p => p.id); [ids[index], ids[index + delta]] = [ids[index + delta], ids[index]]; void mutate("PUT", { photo_ids: ids }); }
  return <section className="photos"><h3>Property gallery</h3><p>Add general hotel photographs here. Add each room’s photos within its room type in step 3. JPG, PNG or WebP, up to 5 MB each.</p>
    {!gallery && !error && <p role="status">Loading property photos…</p>}
    <div className="photo-grid">{gallery?.data.map((photo, index) => <figure key={photo.id}><Image src={`/api/v1/${path}/${photo.id}`} alt={photo.caption} width={320} height={200} unoptimized /><figcaption>{photo.caption}{index === 0 ? " · Cover photo" : ""}</figcaption>
      <button type="button" className="secondary" disabled={busy || blocked || index === 0} onClick={() => move(index, -1)}>Move photo earlier</button><button type="button" className="secondary" disabled={busy || blocked || index === gallery.data.length - 1} onClick={() => move(index, 1)}>Move photo later</button>
      <button type="button" className="secondary" disabled={busy || blocked} onClick={() => { if (window.confirm("Remove this property photo?")) void mutate("DELETE", {}, `/${photo.id}`); }}>Remove property photo</button>
    </figure>)}</div>
    {gallery && <form ref={form} onSubmit={upload} onChange={() => { dirty.current = true; }} className="form-grid"><fieldset className="form-grid full" disabled={busy || blocked}><label>Photo<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required /></label><label>Photo caption<input name="caption" required maxLength={255} /></label><button disabled={gallery.data.length >= 50}>{busy ? "Saving photo…" : "Upload photo"}</button></fieldset></form>}
    {error && <p role="alert" className="error">{error}</p>}
    {blocked && <p className="notice">The gallery may have changed or the upload may have completed. Inspect the saved photos before another write; nothing will be retried automatically.</p>}
    <button type="button" className="secondary" disabled={busy} onClick={reconcile}>Reload property gallery and discard selected upload</button>
  </section>;
}
