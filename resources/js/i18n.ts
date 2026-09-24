import { ref } from 'vue';
import nl from '../../lang/nl.json';

export type Locale = 'en' | 'nl';
type Replacements = Record<string, string | number>;

const dutch = nl as Record<string, string>;
export const activeLocale = ref<Locale>(document.documentElement.lang.toLowerCase().startsWith('nl') ? 'nl' : 'en');

export function setLocale(locale: Locale): void {
  activeLocale.value = locale;
  document.documentElement.lang = locale;
}

export function tr(key: string, replacements: Replacements = {}): string {
  let value = activeLocale.value === 'nl' ? (dutch[key] ?? key) : key;
  for (const [name, replacement] of Object.entries(replacements)) {
    value = value.replaceAll(`:${name}`, String(replacement));
  }
  return value;
}

export function localeTag(): string {
  return activeLocale.value === 'nl' ? 'nl-BE' : 'en-GB';
}

declare module 'vue' {
  interface ComponentCustomProperties {
    $t: typeof tr;
  }
}
