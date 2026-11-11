import '../css/app.css';
import { createApp, h } from 'vue';
import type { DefineComponent } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { loadEcho } from './echo';
import '@fontsource/barlow/400.css';
import '@fontsource/barlow/500.css';
import '@fontsource/barlow/600.css';
import '@fontsource/barlow-condensed/600.css';
import '@fontsource/barlow-condensed/700.css';
import '@fontsource/barlow-condensed/800.css';
import { tr, setLocale, activeLocale } from './i18n';

// Pages load on demand: a volunteer on the race-day link never downloads the admin screens.
const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue');

createInertiaApp({
  title: (title) => title ? `${title} · Triathlon Timing` : 'Triathlon Timing',
  resolve: (name) => pages[`./Pages/${name}.vue`](),
  setup({ el, App, props, plugin }) {
    if (props.initialPage.props.auth && (props.initialPage.props.auth as { user?: unknown }).user) void loadEcho();
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
  progress: { color: '#ff5b14' },
});

// A background refresh interrupted by navigation or a dropped connection is retried by
// the next poll; it is not an application error.
window.addEventListener('unhandledrejection', (event) => {
  const name = (event.reason as { name?: string } | undefined)?.name;
  if (name === 'HttpNetworkError' || name === 'HttpCancelledError') event.preventDefault();
});

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => undefined));
}
