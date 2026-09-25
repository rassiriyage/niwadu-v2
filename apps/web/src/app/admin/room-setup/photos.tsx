"use client";

import Image from "next/image";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { useOperations } from "../operations/forms";
import { ApiError } from "@/lib/admin-api";
import type { SaveRegistry } from "./draft-form";
import type { Room } from "./index";

export default function Gallery({ room, root, currentRoom, update, registry }: { room: Room; root: string; currentRoom: () => Room; update: (room: Room) => void; registry: SaveRegistry }) {
  const request = useOperations(), form = useRef<HTMLFormElement>(null), dirty = useRef(false), saving = useRef(false);
  const [busy, setBusy] = useState(false), [blocked, setBlocked] = useState(false), [error, setError] = useState("");
  const key = `${root}:photos`;
  useEffect(() => {
    registry.set(key, async () => { if (saving.current || dirty.current) throw new Error("Upload or discard the selected room photo before continuing."); });
    const warn = (event: BeforeUnloadEvent) => { if (dirty.current || saving.current) event.preventDefault(); };
    window.addEventListener("beforeunload", warn);
    return () => { registry.delete(key); window.removeEventListener("beforeunload", warn); };
  }, [registry, key]);
  async function changePhoto(method: string, data: unknown, suffix = "") {
    if (saving.current) return;
    saving.current = true; setBusy(true); setError("");
    try {
      for (const [id, flush] of registry) if (id !== key) await flush();
      const latest = currentRoom();
      if (data instanceof FormData) data.set("version", String(latest.version));
      else data = { ...(data as object), version: latest.version };
      const result = await request<{ data: Room }>(`${root}/photos${suffix}`, method, data);
      update(result.data); if (method === "POST") { dirty.current = false; form.current?.reset(); }
    } catch (e) {
      setError((e as Error).message);
      setBlocked(!(e instanceof ApiError) || e.status === 409 || e.status >= 500 || e.status === 408);
    } finally { saving.current = false; setBusy(false); }
  }
  function upload(event: FormEvent<HTMLFormElement>) { event.preventDefault(); void changePhoto("POST", new FormData(event.currentTarget)); }
  async function reconcile() {
    if (dirty.current && !window.confirm("Discard the selected upload and inspect the saved room gallery?")) return;
    setBusy(true);
    try { update((await request<{ data: Room }>(`${root}/photos`)).data); dirty.current = false; form.current?.reset(); setBlocked(false); setError(""); }
    catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  return <section className="photos"><h4>Photos of {room.name}</h4><p>JPG, PNG or WebP, up to 5 MB each. These photos belong to this room type, separately from the property gallery.</p>
    <div className="photo-grid">{room.photos.map((photo, index) => <figure key={photo.id}><Image src={photo.url} alt={photo.caption} width={320} height={200} unoptimized /><figcaption>{photo.caption}{index === 0 ? " · Cover photo" : ""}</figcaption>
      <button type="button" className="secondary" disabled={busy || blocked || index === 0} onClick={() => { const ids = room.photos.map(p => p.id); [ids[index - 1], ids[index]] = [ids[index], ids[index - 1]]; void changePhoto("PUT", { photo_ids: ids }); }}>Move photo earlier</button>
      <button type="button" className="secondary" disabled={busy || blocked || index === room.photos.length - 1} onClick={() => { const ids = room.photos.map(p => p.id); [ids[index], ids[index + 1]] = [ids[index + 1], ids[index]]; void changePhoto("PUT", { photo_ids: ids }); }}>Move photo later</button>
      <button type="button" className="secondary" disabled={busy || blocked} onClick={() => { if (window.confirm("Remove this photo from this room’s gallery?")) void changePhoto("DELETE", {}, `/${photo.id}`); }}>Remove room photo</button>
    </figure>)}</div>
    <form ref={form} onSubmit={upload} onChange={() => { dirty.current = true; }} className="form-grid"><fieldset className="form-grid full" disabled={busy || blocked}><label>Room photo<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required /></label><label>Room photo caption<input name="caption" required maxLength={255} /></label><button disabled={room.photos.length >= 50}>{busy ? "Saving photo…" : "Upload room photo"}</button></fieldset></form>
    {error && <p role="alert" className="error">{error}</p>}
    {blocked && <p className="notice">The upload or photo change may have completed. Inspect the saved gallery before selecting another upload. Nothing will be retried automatically.</p>}
    <button type="button" className="secondary" disabled={busy} onClick={reconcile}>Reload gallery and discard selected upload</button>
  </section>;
}
