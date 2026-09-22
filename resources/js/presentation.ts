export function formatDate(value:string):string {
  const date=new Date(value.slice(0,10)+'T12:00:00Z');
  return Number.isNaN(date.getTime())?value:new Intl.DateTimeFormat('en-GB',{day:'numeric',month:'short',year:'numeric',timeZone:'UTC'}).format(date);
}
