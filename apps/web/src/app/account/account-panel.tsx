"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { FreshLink } from "../fresh-link";
import { getTravellerSession, travellerWrite, TravellerError, type Traveller } from "../../lib/traveller-api";

export function AccountPanel({ register }: { register: boolean }) {
  const router = useRouter();
  const [user, setUser] = useState<Traveller | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const form = useRef<HTMLFormElement>(null);
  const generation = useRef(0);
  const refresh = useCallback(() => {
    const version = ++generation.current;
    return getTravellerSession().then(session => { if (version === generation.current) { setUser(session.user); setError(""); } }).catch(() => { if (version === generation.current) setError("Your account could not be loaded. Please try again."); }).finally(() => { if (version === generation.current) setLoading(false); });
  }, []);
  useEffect(() => {
    void refresh();
    const onReturn = () => { setLoading(true); setUser(null); void refresh(); };
    window.addEventListener("focus", onReturn);
    const currentGeneration = generation;
    return () => { currentGeneration.current++; window.removeEventListener("focus", onReturn); };
  }, [refresh]);
  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (busy) return;
    setBusy(true); setError(""); setFields({});
    const values = new FormData(event.currentTarget);
    const payload = { email: String(values.get("email") || ""), password: String(values.get("password") || ""), ...(register ? { name: String(values.get("name") || ""), password_confirmation: String(values.get("password_confirmation") || "") } : {}) };
    if (register && ([...payload.password].length < 12 || new TextEncoder().encode(payload.password).length > 72)) {
      const message = [...payload.password].length < 12 ? "Use at least 12 characters for your password." : "Your password exceeds 72 UTF-8 bytes. Use a shorter password; some characters use more than one byte.";
      setFields({ password: [message] }); setError(message); setBusy(false);
      requestAnimationFrame(() => form.current?.querySelector<HTMLInputElement>('input[name="password"]')?.focus());
      return;
    }
    try {
      await travellerWrite(register ? "register" : "login", payload);
      form.current?.reset();
      router.replace("/cover"); router.refresh();
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "We could not sign you in.");
      if (failure instanceof TravellerError) setFields(failure.fields);
      setBusy(false);
    }
  }
  async function logout() {
    if (!user || busy) return;
    setBusy(true); setError("");
    try { await travellerWrite("logout", undefined, user.id); generation.current++; setUser(null); }
    catch (failure) { if (failure instanceof TravellerError && failure.status === 401) { generation.current++; setUser(null); } setError(failure instanceof Error ? failure.message : "Sign out failed. Please try again."); }
    finally { setBusy(false); }
  }
  if (loading) return <p role="status">Loading your account…</p>;
  return <section className="account-card"><FreshLink className="account-back" href="/">← Back to explore</FreshLink>
    <h1>{user ? "Your account" : register ? "Create your Niwadu account" : "Welcome back"}</h1>
    {error && <div className="account-error" role="alert"><p>{error}</p><button type="button" onClick={() => { setLoading(true); setUser(null); void refresh(); }}>Reload account</button></div>}
    {user ? <><p className="account-lede">Signed in as {user.name}</p><p>{user.email}</p><p className="account-lede">Your travel map is private to your account.</p><div className="account-actions"><FreshLink className="primary" href="/cover">Open my travel map</FreshLink><button className="account-secondary" disabled={busy} onClick={() => void logout()}>{busy ? "Signing out…" : "Sign out"}</button></div></> : <>
      <p className="account-lede">{register ? "Keep your visited districts in your own private travel map." : "Sign in to view and update your private travel map."}</p>
      <form ref={form} onSubmit={submit}>
        {register && <label>Your name<input name="name" autoComplete="name" required maxLength={255} disabled={busy} aria-invalid={Boolean(fields.name)} />{fields.name?.map(message => <span className="account-field-error" key={message}>{message}</span>)}</label>}
        <label>Email address<input name="email" type="email" autoComplete="email" required maxLength={255} disabled={busy} aria-invalid={Boolean(fields.email)} />{fields.email?.map(message => <span className="account-field-error" key={message}>{message}</span>)}</label>
        <label>Password<input name="password" type="password" autoComplete={register ? "new-password" : "current-password"} required maxLength={1024} disabled={busy} aria-describedby={register ? "password-help" : undefined} aria-invalid={Boolean(fields.password)} />{fields.password?.map(message => <span className="account-field-error" key={message}>{message}</span>)}</label>
        {register && <p id="password-help" className="account-password-help">Use at least 12 characters and no more than 72 UTF-8 bytes. Some characters use more than one byte.</p>}
        {register && <label>Confirm password<input name="password_confirmation" type="password" autoComplete="new-password" required maxLength={1024} disabled={busy} aria-invalid={Boolean(fields.password_confirmation)} />{fields.password_confirmation?.map(message => <span className="account-field-error" key={message}>{message}</span>)}</label>}
        <button className="primary" disabled={busy}>{busy ? "Please wait…" : register ? "Create account" : "Sign in"}</button>
      </form>
      <p className="account-switch">{register ? "Already have an account?" : "New to Niwadu?"} <FreshLink href={register ? "/account" : "/account?mode=register"}>{register ? "Sign in" : "Create an account"}</FreshLink></p>
    </>}
  </section>;
}
