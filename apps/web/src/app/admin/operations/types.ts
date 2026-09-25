export type Room = { id: number; name: string; max_occupancy: number; status: string; version: number };
export type Pool = { id: number; owner: string; sales_state: string; timezone: string; version: number };
export type Plan = { id: number; name: string; status: string; currency: string; meal_plan?: string | null; version: number; policy: { version: string | null; text: string | null } };
export type Night = { stay_date: string; configured: boolean; version?: number; capacity?: number; held?: number; sold?: number; base_minor?: number | null; tax_minor?: number | null; fee_minor?: number | null; mandatory_charges_complete?: boolean; stop_sell?: boolean; min_stay?: number; max_stay?: number; closed_to_arrival?: boolean; closed_to_departure?: boolean };
export type Page<T> = { data: T[]; meta: { current_page: number; last_page: number } };
