import type { Checkpoint } from './types/index';
import { localeTag, tr } from './i18n';

type Course = { settings?: Record<string, unknown> };
type Point = Pick<Checkpoint, 'kind'|'discipline'|'distance_km'>;
function distance(value: unknown): number | null {
  if(value===null || value===undefined || value==='')return null;
  const number=Number(value);
  return Number.isFinite(number)&&number>=0 ? number : null;
}
function number(value:number):string {
  return new Intl.NumberFormat(localeTag(),{maximumFractionDigits:3}).format(value);
}
export function checkpointTotalKm(race: Course, checkpoint: Point): number | null {
  if(checkpoint.kind==='start')return 0;
  const leg=distance(checkpoint.distance_km);
  if(leg===null)return null;
  const swim=distance(race.settings?.swim_km);
  const bike=distance(race.settings?.bike_km);
  if(checkpoint.discipline==='swim')return leg;
  if(checkpoint.discipline==='bike'&&swim!==null)return Math.round((swim+leg)*1000)/1000;
  if(checkpoint.discipline==='run'&&swim!==null&&bike!==null)return Math.round((swim+bike+leg)*1000)/1000;
  return null;
}
export function checkpointDistanceText(race: Course, checkpoint: Point): string {
  if(checkpoint.kind==='start')return tr(':total km total',{total:number(0)});
  const leg=distance(checkpoint.distance_km);
  const total=checkpointTotalKm(race,checkpoint);
  const sport=checkpoint.discipline ? {swim:tr('Swim'),bike:tr('Bike'),run:tr('Run')}[checkpoint.discipline] : null;
  const legText=leg!==null&&sport ? `${sport}: ${number(leg)} km` : tr('Leg distance not set');
  return `${legText} · ${total===null?tr('Total distance unavailable'):tr(':total km total',{total:number(total)})}`;
}
