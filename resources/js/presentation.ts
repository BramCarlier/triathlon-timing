import { localeTag } from './i18n';

export function formatDate(value:string):string {
  const date=new Date(value.slice(0,10)+'T12:00:00Z');
  return Number.isNaN(date.getTime())?value:new Intl.DateTimeFormat(localeTag(),{day:'numeric',month:'short',year:'numeric',timeZone:'UTC'}).format(date);
}

export function formatDateTime(value:string|Date):string {
  const date=value instanceof Date?value:new Date(value);
  return Number.isNaN(date.getTime())?String(value):new Intl.DateTimeFormat(localeTag(),{dateStyle:'medium',timeStyle:'short'}).format(date);
}
