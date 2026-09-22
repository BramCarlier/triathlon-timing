export type UserRole = 'admin' | 'organizer' | 'athlete';
export interface AuthUser { id: number; name: string; email: string; role: UserRole; athlete_id?: number | null }
export interface PageProps extends Record<string, unknown> { auth: { user: AuthUser | null }; flash: { success?: string; error?: string } }
export interface Race { id: number; name: string; slug: string; event_date: string; timezone: string; status: string; started_at?: string | null; finished_at?: string | null; settings?: Record<string, unknown>; entries_count?: number }
export interface Checkpoint { id: number; race_id: number; name: string; code: string; sequence: number; discipline?: 'swim'|'bike'|'run'|null; kind: 'start'|'split'|'transition'|'finish'; distance_km?: string|null; is_required: boolean; is_active: boolean }
export interface StationParticipant { id: number; status?:string; bib_number: string | null; type: 'solo'|'relay'; name: string; members: Array<{discipline:string;name:string}>; completed_checkpoint_ids: number[] }
