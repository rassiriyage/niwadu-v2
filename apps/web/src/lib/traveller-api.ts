export type Traveller = { id: number; name: string; email: string };
export type TravellerSession = { user: Traveller | null; csrf_token: string };
export type Coverage = { districts: string[]; version: number };

export class TravellerError extends Error {
  constructor(message: string, public status: number, public fields: Record<string, string[]> = {}) { super(message); }
}
export async function travellerRead<T>(path: "session" | "me/coverage"): Promise<T> {
  return request<T>(path);
}
async function request<T>(path: string, method = "GET", data?: unknown, token?: string, idempotencyKey?: string): Promise<T> {
  let response: Response;
  try {
    response = await fetch(`/api/v1/${path}`, {
      method, credentials: "same-origin", cache: "no-store", signal: AbortSignal.timeout(15000),
      headers: { Accept: "application/json", ...(idempotencyKey ? { "Idempotency-Key": idempotencyKey } : {}), ...(token ? { "X-CSRF-TOKEN": token, "Content-Type": "application/json" } : {}) },
      body: data === undefined ? undefined : JSON.stringify(data),
    });
  } catch { throw new TravellerError("We could not reach your account. Please try again.", 0); }
  if (response.status === 204) return undefined as T;
  const result = await response.json().catch(() => null);
  if (!response.ok) {
    const fields = result?.errors && typeof result.errors === "object" ? result.errors : {};
    const message = response.status === 401 ? "Your session has ended. Sign in again to continue." : response.status === 429 ? "Too many attempts. Please wait before trying again." : typeof result?.message === "string" ? result.message : "We could not complete this request. Please try again.";
    throw new TravellerError(message, response.status, fields);
  }
  if (!result || typeof result !== "object") throw new TravellerError("Your account service is temporarily unavailable.", 503);
  return result as T;
}
export async function getTravellerSession() {
  const session = await travellerRead<TravellerSession>("session");
  if (typeof session.csrf_token !== "string" || !(session.user === null || (Number.isInteger(session.user?.id) && typeof session.user?.name === "string" && typeof session.user?.email === "string"))) throw new TravellerError("Your account service is temporarily unavailable.", 503);
  return session;
}
export async function travellerWrite<T>(path: "login" | "register" | "logout" | "me/coverage", data?: unknown, expectedUser?: number): Promise<T> {
  const session = await getTravellerSession();
  if (expectedUser !== undefined && session.user?.id !== expectedUser) throw new TravellerError("The signed-in account changed. Sign in again before continuing.", 401);
  if ((path === "login" || path === "register") && session.user) throw new TravellerError("An account is already signed in. Reload to continue with that account.", 409);
  return request<T>(path, path === "me/coverage" ? "PUT" : "POST", data, session.csrf_token);
}

export async function itineraryRequest<T>(path: string, expectedUser: number, method = "GET", data?: unknown, idempotencyKey?: string): Promise<T> {
  const before = await getTravellerSession();
  if (before.user?.id !== expectedUser) throw new TravellerError("The signed-in account changed. Sign in again to continue.", 401);
  const result = await request<T>(`me/itineraries${path}`, method, data, method === "GET" ? undefined : before.csrf_token, idempotencyKey);
  const after = await getTravellerSession();
  if (after.user?.id !== expectedUser) throw new TravellerError("The signed-in account changed. Sign in again to continue.", 401);
  return result;
}
