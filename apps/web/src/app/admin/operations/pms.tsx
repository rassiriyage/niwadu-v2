"use client";
import { useCallback, useEffect, useState } from "react";
import { ApiError } from "@/lib/admin-api";
import { canLeaveOperations, Check, checked, Field, OperationForm, text, useOperations } from "./forms";
import type { Page } from "./types";
type Provider = { provider: string; label: string; checkout_enabled: boolean; capabilities: { booking_mode: string } };
type Connection = { id: number; provider: string; environment: string; endpoint_origin: string; external_property_id: string; enabled: boolean; credentials_configured: boolean; last_verified_at: string | null; last_sync_at: string | null; has_error: boolean };
export default function Pms({ hotelId }: { hotelId: number }) {
  const request = useOperations(), root = `hotels/${hotelId}/pms-connections`;
  const [providers, setProviders] = useState<Provider[]>(), [connections, setConnections] = useState<Connection[]>(), [selected, setSelected] = useState<Connection | null>(null), [page, setPage] = useState(1), [last, setLast] = useState(1), [revision, setRevision] = useState(0), [error, setError] = useState("");
  const load = useCallback(async () => { const response = await request<Page<Connection>>(`${root}?page=${page}`); setConnections(response.data); setLast(response.meta.last_page); if (selected) setSelected(response.data.find(c => c.id === selected.id) ?? null); setRevision(r => r + 1); }, [request, root, page, selected]);
  useEffect(() => { let active = true; Promise.all([request<{ data: Provider[] }>("pms/providers"), request<Page<Connection>>(`${root}?page=${page}`)]).then(([p, c]) => { if (active) { setProviders(p.data); setConnections(c.data); setLast(c.meta.last_page); setError(""); } }).catch(e => { if (active) setError(e.message); }); return () => { active = false; }; }, [request, root, page]);
  if (!providers || !connections) return <p role={error ? "alert" : "status"}>{error || "Loading PMS configuration…"}</p>;
  return <section className="panel"><h2>PMS configuration</h2><p className="notice">Platform administrators only. These are saved settings, not proof of a working connection. Saving does not contact a provider, synchronize rooms, or enable checkout.</p>
    <ul>{providers.map(p => <li key={p.provider}>{p.label}: {p.capabilities.booking_mode === "post_sale_import" ? "post-sale import contract only" : "integration unsupported"}. Checkout {p.checkout_enabled ? "supported by contract" : "unavailable"}.</li>)}</ul>
    {connections.length === 0 && <p>No PMS settings saved for this hotel.</p>}
    <div className="room-actions">{connections.map(c => <button className="secondary" key={c.id} aria-pressed={selected?.id === c.id} onClick={() => { if (canLeaveOperations()) setSelected(c); }}>{c.provider} · {c.external_property_id}</button>)}<button onClick={() => { if (canLeaveOperations()) { setSelected(null); setRevision(r => r + 1); } }}>New PMS configuration</button></div>
    {last > 1 && <nav className="pagination" aria-label="PMS configuration pages"><button disabled={page === 1} onClick={() => { if (canLeaveOperations()) { setPage(page - 1); setSelected(null); } }}>Previous configurations</button><span>{page} / {last}</span><button disabled={page === last} onClick={() => { if (canLeaveOperations()) { setPage(page + 1); setSelected(null); } }}>Next configurations</button></nav>}
    <PmsForm key={`${selected?.id ?? "new"}:${revision}`} providers={providers} connection={selected} root={root} reload={load} saved={async result => { setSelected(result); const response = await request<Page<Connection>>(`${root}?page=${page}`); setConnections(response.data); setLast(response.meta.last_page); setRevision(r => r + 1); }} />
  </section>;
}
function PmsForm({ providers, connection, root, reload, saved }: { providers: Provider[]; connection: Connection | null; root: string; reload: () => Promise<void>; saved: (connection: Connection) => Promise<void> }) {
  const request = useOperations();
  const [credentials, setCredentials] = useState([{ key: "", value: "" }]);
  return <><h3>{connection ? "Edit saved configuration" : "Add configuration"}</h3>
    {connection && <dl className="review-details"><dt>Credentials</dt><dd>{connection.credentials_configured ? "Configured · values are never returned" : "Not configured"}</dd><dt>Last verification</dt><dd>{connection.last_verified_at ?? "Never verified"}</dd><dt>Last synchronization</dt><dd>{connection.last_sync_at ?? "Never synchronized"}</dd><dt>Error state</dt><dd>{connection.has_error ? "An error is recorded. Ask the integration team to investigate." : "No error recorded; this does not establish connection health."}</dd></dl>}
    <OperationForm label="Save PMS settings" reload={reload} save={async data => {
      const active = credentials.filter(c => c.key || c.value);
      if ((!connection && active.length === 0) || active.some(c => !c.key || !c.value) || new Set(active.map(c => c.key)).size !== active.length) throw new ApiError("Enter a unique name and value for each credential. New configurations require credentials.", 422);
      const payload = { environment: text(data, "environment"), endpoint_origin: text(data, "endpoint_origin"), enabled: checked(data, "enabled"), ...(active.length ? { credentials: Object.fromEntries(active.map(c => [c.key, c.value])) } : {}), ...(!connection ? { provider: text(data, "provider"), external_property_id: text(data, "external_property_id"), inventory_mode: "pms" } : {}) };
      const result = await request<{ data: Connection }>(connection ? `pms-connections/${connection.id}` : root, connection ? "PATCH" : "POST", payload);
      setCredentials([{ key: "", value: "" }]); await saved(result.data);
    }}>
      {connection ? <p>Provider: {connection.provider}<br />Property ID: {connection.external_property_id}<br />Inventory mode: PMS</p> : <><label>Provider<select name="provider">{providers.map(p => <option key={p.provider} value={p.provider}>{p.label}</option>)}</select></label><Field label="Provider property ID" name="external_property_id" required maxLength={255} /></>}
      <label>Environment<select name="environment" defaultValue={connection?.environment ?? "sandbox"}><option value="sandbox">Sandbox</option><option value="production">Production</option></select></label>
      <Field label="HTTPS endpoint origin" name="endpoint_origin" type="url" defaultValue={connection?.endpoint_origin} placeholder="https://pms.example.com" required maxLength={2048} />
      <p className="full hint">Use a public HTTPS hostname without a path, query, embedded credentials or IP address. Private and local hosts are rejected.</p>
      <div className="full"><h4>{connection ? "Replace credentials (optional)" : "Credentials"}</h4><p className="hint">Enter the complete replacement set. Leave all fields empty to keep existing credentials. Values remain only in this form until submitted; they are never saved in browser storage.</p>
        {credentials.map((c, i) => <div className="form-grid" key={i}><Field label={`Credential ${i + 1} name`} name={`credential_name_${i}`} value={c.key} onChange={e => setCredentials(current => current.map((value, index) => index === i ? { ...value, key: e.target.value } : value))} /><Field label={`Credential ${i + 1} value`} name={`credential_value_${i}`} type="password" autoComplete="new-password" maxLength={4096} value={c.value} onChange={e => setCredentials(current => current.map((value, index) => index === i ? { ...value, value: e.target.value } : value))} /></div>)}
        <button type="button" className="secondary" onClick={() => setCredentials(c => [...c, { key: "", value: "" }])}>Add credential field</button>
      </div><Check name="enabled" label="Mark saved configuration enabled (does not verify or activate checkout)" value={connection?.enabled} />
    </OperationForm></>;
}
