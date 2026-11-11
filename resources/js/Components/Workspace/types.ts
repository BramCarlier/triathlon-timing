
export interface Official {
  id: number;
  name: string;
  email: string;
  role?: 'admin'|'official';
}

export interface CheckpointAssignment {
  id: number;
  race_id: number;
  checkpoint_id: number;
  user_id: number;
  user: Official;
  checkpoint?: { id:number; name:string };
}

export interface AthleteChoice {
  id: number;
  full_name: string;
  first_name: string;
  last_name: string;
  email: string | null;
  club: string | null;
  race_count: number;
  already_in_race: boolean;
  races: Array<{ id:number; name:string; event_date:string }>;
  recent_bibs: string[];
}

export interface ReadinessCheck { key:string; label:string; ready:boolean; required:boolean; detail:string }
export interface Readiness { ready_to_start:boolean; checks:ReadinessCheck[]; participant_count:number; unassigned_checkpoint_count:number }
