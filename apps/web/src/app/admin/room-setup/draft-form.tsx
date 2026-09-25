"use client";

import { createContext, useCallback, useContext, useEffect, useId, useRef, useState, type ReactNode } from "react";
import { ApiError } from "@/lib/admin-api";

export type Values = Record<string, string | boolean>;
export type SaveRegistry = Map<string, () => Promise<void>>;
type Recovery = { version: number; values: Values; uncertain?: boolean };
const ValidationContext = createContext<Record<string, string[]>>({});

function recover(key: string, initial: Values): Recovery | undefined {
  try {
    const value = JSON.parse(sessionStorage.getItem(key) || "null");
    if (value && Number.isInteger(value.version) && value.version >= 0 && value.values &&
      Object.keys(initial).every(k => typeof value.values[k] === typeof initial[k]) &&
      Object.keys(value.values).length === Object.keys(initial).length) return value;
  } catch { /* Restricted storage must not prevent editing. */ }
}

export default function DraftForm({ storageKey, initial, version, save, reload, registry, children, replayCreate = false, editable = true }: {
  storageKey: string; initial: Values; version: number; save: (values: Values, version: number) => Promise<number>;
  reload: () => Promise<void>; registry: SaveRegistry; children: (values: Values, change: (key: string, value: string | boolean) => void) => ReactNode;
  replayCreate?: boolean; editable?: boolean;
}) {
  const [recovered] = useState(() => recover(storageKey, initial));
  const [values, setValues] = useState(recovered?.values ?? initial);
  const [message, setMessage] = useState(recovered ? "Recovered unsaved inputs. Review them and select Save changes." : "All changes saved");
  const [error, setError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const [blocked, setBlocked] = useState(!!recovered && (recovered.version !== version || !!recovered.uncertain));
  const [hasConflict, setHasConflict] = useState(!!recovered && recovered.version !== version);
  const [storageError, setStorageError] = useState("");
  const current = useRef(values), revision = useRef(recovered?.version ?? version), dirty = useRef(!!recovered);
  const suspended = useRef(!!recovered), uncertain = useRef(!!recovered?.uncertain), conflict = useRef(!!recovered && recovered.version !== version);
  const running = useRef<Promise<void> | null>(null), timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const form = useRef<HTMLFormElement>(null);
  const saveRef = useRef(save);
  useEffect(() => { saveRef.current = save; }, [save]);
  useEffect(() => { if (!dirty.current && !running.current) revision.current = version; }, [version]);
  const persist = useCallback(() => {
    try {
      if (dirty.current) sessionStorage.setItem(storageKey, JSON.stringify({ values: current.current, version: revision.current, uncertain: uncertain.current }));
      else sessionStorage.removeItem(storageKey);
      setStorageError("");
    } catch { setStorageError("This browser cannot retain a recovery copy. Keep this tab open until your changes are saved."); }
  }, [storageKey]);
  const flush = useCallback(async () => {
    if (timer.current) clearTimeout(timer.current);
    if (running.current) await running.current;
    if (!dirty.current) return;
    if (suspended.current || conflict.current || uncertain.current) throw new Error("Review the unsaved room or rate inputs before continuing.");
    const run = async () => {
      const focused = document.activeElement instanceof HTMLElement && form.current?.contains(document.activeElement) ? document.activeElement : null;
      setBusy(true); setMessage("Saving…"); setError(""); setFieldErrors({});
      try {
        revision.current = await saveRef.current(current.current, revision.current);
        dirty.current = false; uncertain.current = false; persist(); setMessage("All changes saved");
      } catch (e) {
        if (e instanceof ApiError && [401, 419].includes(e.status)) { dirty.current = false; uncertain.current = false; persist(); throw e; }
        if (e instanceof ApiError) setFieldErrors(e.fieldErrors);
        conflict.current = e instanceof ApiError && e.status === 409;
        setHasConflict(conflict.current);
        uncertain.current = !(e instanceof ApiError) || e.status >= 500 || e.status === 408;
        suspended.current = true; setBlocked(conflict.current || uncertain.current); persist();
        setError((e as Error).message); setMessage("Changes not saved"); throw e;
      } finally { setBusy(false); requestAnimationFrame(() => { if (focused?.isConnected && document.activeElement === document.body) focused.focus({ preventScroll: true }); }); }
    };
    running.current = run();
    try { await running.current; } finally { running.current = null; }
  }, [persist]);
  useEffect(() => {
    registry.set(storageKey, async () => { try { await flush(); } catch (e) { throw new Error((e as Error).message); } });
    const warn = (event: BeforeUnloadEvent) => { if (dirty.current || running.current) event.preventDefault(); };
    window.addEventListener("beforeunload", warn);
    return () => { registry.delete(storageKey); window.removeEventListener("beforeunload", warn); if (timer.current) clearTimeout(timer.current); };
  }, [registry, storageKey, flush]);
  function change(key: string, value: string | boolean) {
    current.current = { ...current.current, [key]: value }; setValues(current.current); dirty.current = true;
    suspended.current = false; persist(); setMessage("Changes waiting to save");
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => { void flush().catch(() => {}); }, 800);
  }
  async function retry() {
    if (conflict.current || (uncertain.current && !replayCreate)) return;
    suspended.current = false; uncertain.current = false; setBlocked(false);
    await flush().catch(() => {});
  }
  async function discard() {
    if (!window.confirm("Discard these unsaved inputs and load the latest saved record?")) return;
    setBusy(true);
    try { await reload(); dirty.current = false; persist(); }
    catch (e) { setError((e as Error).message); setBusy(false); }
  }
  // The render callback attaches change to input events; it never invokes it during rendering.
  // eslint-disable-next-line react-hooks/refs
  const fields = children(values, change);
  return <form ref={form} className="wizard-catalog-form" onSubmit={e => { e.preventDefault(); void retry(); }}>
    <ValidationContext.Provider value={fieldErrors}><fieldset disabled={!editable || busy || blocked} className="form-grid">{fields}</fieldset></ValidationContext.Provider>
    <p role="status">{message}</p>
    {error && <p className="error" role="alert">{error}</p>}
    {storageError && <p className="error" role="alert">{storageError}</p>}
    {editable && <button disabled={busy || (blocked && (!replayCreate || hasConflict))}>{busy ? "Saving…" : blocked ? "Retry original room creation" : "Save changes"}</button>}
    {blocked && <div className="notice"><p>The saved record may have changed. Your inputs are retained below. Review the current record before writing again.</p><label>Unsaved inputs<textarea readOnly rows={5} value={JSON.stringify(values, null, 2)} /></label><button type="button" className="secondary" disabled={busy} onClick={discard}>Discard inputs and reload latest</button></div>}
    {!editable && <p>Your role cannot edit this record.</p>}
  </form>;
}

export function Input({ label, name, values, change, ...props }: { label: string; name: string; values: Values; change: (key: string, value: string | boolean) => void } & Omit<React.InputHTMLAttributes<HTMLInputElement>, "value" | "onChange">) {
  const id = useId(), errors = useContext(ValidationContext);
  const field = ({ occupancy: "max_occupancy", base: "base_minor", tax: "tax_minor", fee: "fee_minor", min: "min_stay", max: "max_stay", policy_version: "policy.version" } as Record<string, string>)[name] ?? name;
  return <label>{label}<input {...props} aria-label={label} aria-invalid={errors[field] ? true : undefined} aria-describedby={errors[field] ? id : undefined} value={String(values[name] ?? "")} onChange={e => change(name, e.target.value)} />{errors[field] && <span className="field-error" id={id}>{errors[field].join(" ")}</span>}</label>;
}
