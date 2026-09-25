export type StaffUser = { id: number; name: string; email: string; platform_role: "administrator" | "onboarding" | null };
export type Hotel = {
  id: number; version: number; name: string; city: string | null; country: string;
  address: string | null; description: string | null; contact_email: string | null; phone: string | null; status: string;
  permissions: { author_rates?: boolean; manage_inventory: boolean; manage_pms: boolean; edit_profile: boolean; view_staff: boolean; manage_staff: boolean };
};
export type Session = { user: StaffUser | null; csrf_token: string };
export type Member = { id: number; name: string; email: string; role: string };

export type FieldErrors = Record<string, string[]>;

export class ApiError extends Error {
  constructor(message: string, public status: number, public fieldErrors: FieldErrors = {}, public code?: string) { super(message); }
}

export async function api<T>(path: string, method = "GET", data?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (method !== "GET") {
    const session = await api<Session>("session");
    headers["X-CSRF-TOKEN"] = session.csrf_token;
    if (!(data instanceof FormData)) headers["Content-Type"] = "application/json";
  }
  const response = await fetch(`/api/v1/${path}`, {
    method, headers, credentials: "same-origin", cache: "no-store",
    body: data instanceof FormData ? data : data === undefined ? undefined : JSON.stringify(data),
  });
  if (response.status === 204) return undefined as T;
  const result = await response.json().catch(() => ({}));
  if (!response.ok) {
    const messages = result.errors ? Object.values(result.errors).flat().join(" ") : result.message;
    throw new ApiError(response.status === 401 ? "Your session has ended. Please sign in again." : messages || "We could not save your changes. Please try again.", response.status, result.errors || {}, typeof result.code === "string" ? result.code : undefined);
  }
  return result;
}

export const roles: Record<string, string> = {
  hotel_manager: "Hotel manager", reservations: "Reservations staff", inventory_manager: "Inventory manager", viewer: "Viewer",
};
