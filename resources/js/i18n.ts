import { ref } from 'vue';
import nl from '../../lang/nl.json' with { type: 'json' };

export type Locale = 'en' | 'nl';
type Replacements = Record<string, string | number>;

const dutch = nl as Record<string, string>;
export const activeLocale = ref<Locale>((typeof document === 'undefined' ? 'en' : document.documentElement.lang).toLowerCase().startsWith('nl') ? 'nl' : 'en');

export function setLocale(locale: Locale): void {
  activeLocale.value = locale;
  if (typeof document !== 'undefined') document.documentElement.lang = locale;
}

export function tr(key: string, replacements: Replacements = {}): string {
  const value = activeLocale.value === 'nl' ? (dutch[key] ?? key) : key;
  return value.replace(/:([A-Za-z_][A-Za-z0-9_]*)/g, (token, name: string) =>
    Object.hasOwn(replacements, name) ? String(replacements[name]) : token);
}

export function localeTag(): string {
  return activeLocale.value === 'nl' ? 'nl-BE' : 'en-GB';
}

declare module 'vue' {
  interface ComponentCustomProperties {
    $t: typeof tr;
  }
}
