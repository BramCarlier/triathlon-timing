export function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export function formatDuration(ms: number | null | undefined, millis = false): string {
  if (ms === null || ms === undefined || Number.isNaN(ms)) return '—';
  const total = Math.max(0, Math.floor(ms));
  const hours = Math.floor(total / 3_600_000);
  const minutes = Math.floor((total % 3_600_000) / 60_000);
  const seconds = Math.floor((total % 60_000) / 1_000);
  const fraction = total % 1_000;
  const base = [hours, minutes, seconds].map((value) => String(value).padStart(2, '0')).join(':');
  return millis ? `${base}.${String(fraction).padStart(3, '0')}` : base;
}

export function uuid(): string {
  return crypto.randomUUID();
}

export async function jsonRequest<T>(url: string, options: RequestInit = {}): Promise<{ response: Response; data: T }> {
  const response = await fetch(url, {
    credentials: 'same-origin',
    ...options,
    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), ...(options.headers ?? {}) },
  });
  let data: T;
  try { data = await response.json() as T; } catch { data = {} as T; }
  return { response, data };
}
