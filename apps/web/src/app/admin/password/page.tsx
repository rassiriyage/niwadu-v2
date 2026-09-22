"use client";

import Link from "next/link";
import { useState, useSyncExternalStore, type FormEvent } from "react";
import { api } from "@/lib/admin-api";

function subscribe(callback: () => void) {
  window.addEventListener("hashchange", callback);
  return () => window.removeEventListener("hashchange", callback);
}

export default function PasswordSetup() {
  const fragment = useSyncExternalStore(subscribe, () => window.location.hash, () => "");
  const params = new URLSearchParams(fragment.slice(1));
  const token = params.get("token"), email = params.get("email");
  const details = token && email ? { token, email } : undefined;
  const [error, setError] = useState("");
  const [done, setDone] = useState(false);
  const [busy, setBusy] = useState(false);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError("");
    try { await api("password/setup", "POST", { ...Object.fromEntries(new FormData(event.currentTarget)), ...details }); window.history.replaceState(null, "", window.location.pathname); setDone(true); }
    catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  return <main className="signin"><Link className="wordmark" href="/admin">niwadu<span>hotel management</span></Link><h1>{done ? "Your password is ready" : "Set your password"}</h1>
    {done ? <><p>Your account is ready to use.</p><Link className="button-link" href="/admin">Sign in</Link></> : <><p>Choose a password with at least 12 characters.</p>{details && <form className="stack" onSubmit={submit}><p>{details.email}</p><label>New password<input type="password" name="password" minLength={12} maxLength={1024} autoComplete="new-password" required /></label><label>Confirm password<input type="password" name="password_confirmation" minLength={12} maxLength={1024} autoComplete="new-password" required /></label><button disabled={busy}>{busy ? "Saving…" : "Set password"}</button></form>}</>}
    {!details && !done && <p>Open the password-setup link from your invitation. For an expired link, contact your Niwadu administrator.</p>}
    {error && <p role="alert" className="error">{error}</p>}
  </main>;
}
