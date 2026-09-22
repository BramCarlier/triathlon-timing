/** Laravel rotates this cookie on sign-in; an Inertia page's meta tag can be stale. */
export function csrfHeaders(cookies:string,metaToken:string):Record<string,string> {
  const value=cookies.split(';').map(v=>v.trim()).find(v=>v.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
  if(value){try{return {'X-XSRF-TOKEN':decodeURIComponent(value)};}catch{/* Fall back only for a malformed cookie. */}}
  return {'X-CSRF-TOKEN':metaToken};
}
