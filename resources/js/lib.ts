import { csrfHeaders } from './csrf';
export function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export { formatDuration } from './duration';

export function uuid(): string {
  return crypto.randomUUID();
}

export async function jsonRequest<T>(url: string, options: RequestInit = {}): Promise<{ response: Response; data: T }> {
  const response = await fetch(url, {
    credentials: 'same-origin',
    ...options,
    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', ...csrfHeaders(document.cookie,csrfToken()), ...(options.headers ?? {}) },
  });
  let data: T;
  try { data = await response.json() as T; } catch { data = {} as T; }
  return { response, data };
}

export function bibLabel(bib: string | null | undefined): string {
  return bib ? `#${bib}` : 'No bib';
}
