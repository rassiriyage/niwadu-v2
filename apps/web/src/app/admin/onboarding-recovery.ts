export type Room = { name: string | null; occupancy: number | null; quantity: number | null; rate: number | null };
export type Fields = { name: string; city: string | null; country: string; address: string | null; contact_email: string | null; phone: string | null; description: string | null; property_type: string | null; amenities: string[]; rooms: Room[]; inventory_request: string | null; check_in: string | null; check_out: string | null; cancellation_policy: string | null; guest_rules: string | null };

export type Recovery = { version: number; step: number; fields: Fields; pendingKeys: (keyof Fields)[] };
const prefix = "niwadu-onboarding:";
export const recoveryKey = (userId: number, hotelId: number) => `${prefix}${userId}:${hotelId}`;

function validFields(value: unknown): value is Fields {
  if (!value || typeof value !== "object") return false;
  const fields = value as Record<string, unknown>;
  if (!["name", "country"].every(key => typeof fields[key] === "string")) return false;
  if (!["city", "address", "contact_email", "phone", "description", "property_type", "inventory_request", "check_in", "check_out", "cancellation_policy", "guest_rules"].every(key => fields[key] === null || typeof fields[key] === "string")) return false;
  if (!Array.isArray(fields.amenities) || !fields.amenities.every(item => typeof item === "string")) return false;
  return Array.isArray(fields.rooms) && fields.rooms.every(room => room && typeof room === "object" && (room.name === null || typeof room.name === "string") && ["occupancy", "quantity", "rate"].every(key => room[key] === null || typeof room[key] === "number"));
}

export function readRecovery(key: string): Recovery | undefined {
  try {
    const saved = JSON.parse(sessionStorage.getItem(key) || "null");
    if (saved && Number.isInteger(saved.version) && saved.version >= 0 && Number.isInteger(saved.step) && saved.step >= 1 && saved.step <= 7 && validFields(saved.fields) && Array.isArray(saved.pendingKeys) && saved.pendingKeys.length > 0 && saved.pendingKeys.every((field: unknown) => typeof field === "string" && Object.hasOwn(saved.fields, field))) return saved;
  } catch { /* Unavailable or malformed tab storage must not block opening a draft. */ }
}

export function writeRecovery(key: string, saved?: Recovery): boolean {
  try {
    if (saved) sessionStorage.setItem(key, JSON.stringify(saved));
    else sessionStorage.removeItem(key);
    return true;
  } catch { return false; }
}

export function clearDraftRecovery(): void {
  try {
    for (const key of Object.keys(sessionStorage)) {
      if (key.startsWith(prefix)) sessionStorage.removeItem(key);
    }
  } catch { /* Storage restrictions must never prevent backend sign-out. */ }
}
