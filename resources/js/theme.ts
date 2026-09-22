import { ref } from 'vue';
export type Theme = 'light' | 'dark';
const preferenceKey = 'triathlon-theme';
const system = window.matchMedia('(prefers-color-scheme: dark)');
function savedTheme(): Theme | null {
  try { const value=localStorage.getItem(preferenceKey); return value==='light'||value==='dark'?value:null; }
  catch { return null; }
}
let preference=savedTheme();
export const theme=ref<Theme>(preference??(system.matches?'dark':'light'));
function apply(value:Theme) {
  theme.value=value;
  document.documentElement.dataset.theme=value;
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content',value==='dark'?'#07111f':'#f8fafc');
}
export function toggleTheme() {
  preference=theme.value==='dark'?'light':'dark';
  apply(preference);
  try { localStorage.setItem(preferenceKey,preference); } catch { /* Switching still works when storage is unavailable. */ }
}
system.addEventListener('change',()=>{if(!preference)apply(system.matches?'dark':'light');});
window.addEventListener('storage',event=>{if(event.key===preferenceKey||event.key===null){preference=savedTheme();apply(preference??(system.matches?'dark':'light'));}});
apply(theme.value);
