"use client";

import { createContext, useContext, useRef, useState, type FormEvent, type ReactNode, type InputHTMLAttributes } from "react";
import { ApiError } from "@/lib/admin-api";

export type Request = <T>(path: string, method?: string, data?: unknown) => Promise<T>;
export const OperationsContext = createContext<Request | null>(null);
export function useOperations() { const request = useContext(OperationsContext); if (!request) throw new Error("Operations session is required"); return request; }
export const text = (data: FormData, name: string) => String(data.get(name) ?? "");
export const number = (data: FormData, name: string) => Number(data.get(name));
export const checked = (data: FormData, name: string) => data.get(name) === "on";

export function Field({ label, name, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: string; name: string }) {
  return <label>{label}<input name={name} {...props} /></label>;
}
export function Status({ value = "draft" }: { value?: string }) {
  return <label>Status<select name="status" defaultValue={value}><option value="draft">Draft</option><option value="active">Active</option><option value="archived">Archived</option></select></label>;
}
export function Check({ name, label, value = false }: { name: string; label: string; value?: boolean }) {
  return <label className="ops-check"><input type="checkbox" name={name} defaultChecked={value} />{label}</label>;
}
export function OperationForm({ children, label, save, reload, editable = true }: { children: ReactNode; label: string; save: (data: FormData) => Promise<void>; reload: () => Promise<void>; editable?: boolean }) {
  const [busy, setBusy] = useState(false), [error, setError] = useState(""), [blocked, setBlocked] = useState(false), [message, setMessage] = useState("");
  const form = useRef<HTMLFormElement>(null), summary = useRef<HTMLParagraphElement>(null);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(""); setMessage(""); if (form.current) form.current.dataset.saving = "true";
    try { await save(new FormData(event.currentTarget)); if (form.current) delete form.current.dataset.unsaved; setMessage("Saved."); }
    catch (e) {
      const failure = e as Error;
      setError(failure.message); setBlocked(!(e instanceof ApiError) || e.status === 409 || e.status >= 500);
      requestAnimationFrame(() => summary.current?.focus());
    } finally { if (form.current) delete form.current.dataset.saving; setBusy(false); }
  }
  async function discard() {
    if (!window.confirm("Discard this screen’s unsaved changes and load the latest saved data?")) return;
    setBusy(true);
    try { await reload(); setBlocked(false); setError(""); if (form.current) { form.current.reset(); delete form.current.dataset.unsaved; } }
    catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  return <form ref={form} onSubmit={submit} onChange={() => { if (form.current) form.current.dataset.unsaved = "true"; setMessage(""); }} className="ops-form">
    <fieldset className="form-grid" disabled={!editable || busy}>{children}</fieldset>
    {editable ? <button disabled={busy || blocked}>{busy ? "Saving…" : label}</button> : <p className="hint">Your role has read-only access to these settings.</p>}
    {error && <p ref={summary} tabIndex={-1} className="error" role="alert">{error}</p>}
    {blocked && <div className="notice"><p>Your inputs are retained. The saved record may have changed. Review your inputs before reloading; this action will discard them. No write will be retried automatically.</p><button type="button" className="secondary" disabled={busy} onClick={discard}>Discard edits and reload latest</button></div>}
    {message && <p role="status" className="success">{message}</p>}
  </form>;
}
export function canLeaveOperations() {
  if (document.querySelector(".ops-form[data-saving]")) { window.alert("Please wait for the current save to finish."); return false; }
  return !document.querySelector(".ops-form[data-unsaved]") || window.confirm("Discard unsaved changes before switching views?");
}
