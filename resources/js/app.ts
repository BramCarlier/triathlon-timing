import '../css/app.css';
import '@fortawesome/fontawesome-free/css/all.min.css';
import { createApp, h } from 'vue';
import type { DefineComponent } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { jsonRequest } from './lib';
import { tr, setLocale, activeLocale } from './i18n';

(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

if (import.meta.env.VITE_REVERB_APP_KEY) {
  (window as any).Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: {name:string}) => ({
      authorize: (socketId, callback) => {
        jsonRequest<{auth:string;channel_data?:string;shared_secret?:string}>('/broadcasting/auth',{method:'POST',body:JSON.stringify({socket_id:socketId,channel_name:channel.name}),signal:AbortSignal.timeout(12000)})
          .then(({response,data})=>callback(response.ok?null:new Error(`Live authorization failed (${response.status})`),data))
          .catch(error=>callback(error,null));
      },
    }),
  });
}

const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { eager: true });

createInertiaApp({
  title: (title) => title ? `${title} · Triathlon Timing` : 'Triathlon Timing',
  resolve: (name) => pages[`./Pages/${name}.vue`],
  setup({ el, App, props, plugin }) {
    setLocale(props.initialPage.props.locale === 'nl' ? 'nl' : 'en');
    router.on('beforeUpdate', ({ detail }) => setLocale(detail.page.props.locale === 'nl' ? 'nl' : 'en'));
    router.on('navigate', ({ detail }) => {
      // History can contain server messages from before the language changed.
      if (detail.page.props.locale !== activeLocale.value) router.reload();
    });
    const app = createApp({ render: () => h(App, props) }).use(plugin);
    app.config.globalProperties.$t = tr;
    app.mount(el);
  },
  progress: { color: '#22d3ee' },
});

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => undefined));
}
