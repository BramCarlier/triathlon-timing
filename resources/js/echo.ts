import { jsonRequest } from './lib';

/**
 * Laravel Echo for authenticated pages, loaded on demand so the public
 * race-day link does not download the WebSocket client it never uses.
 */
let pending: Promise<any | null> | null = null;

export function loadEcho(): Promise<any | null> {
  if (!import.meta.env.VITE_REVERB_APP_KEY) return Promise.resolve(null);
  pending ??= Promise.all([import('laravel-echo'), import('pusher-js')]).then(([{ default: Echo }, { default: Pusher }]) => {
    (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;
    const echo = new Echo({
      broadcaster: 'reverb',
      key: import.meta.env.VITE_REVERB_APP_KEY,
      wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
      wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
      wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
      forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
      enabledTransports: ['ws', 'wss'],
      authorizer: (channel: { name: string }) => ({
        authorize: (socketId: string, callback: (error: Error | null, data: any) => void) => {
          jsonRequest<{ auth: string; channel_data?: string; shared_secret?: string }>('/broadcasting/auth', {
            method: 'POST', body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }), signal: AbortSignal.timeout(12000),
          })
            .then(({ response, data }) => callback(response.ok ? null : new Error(`Live authorization failed (${response.status})`), data))
            .catch(error => callback(error, null));
        },
      }),
    });
    (window as any).Echo = echo;
    return echo;
  }).catch(() => null);
  return pending;
}
