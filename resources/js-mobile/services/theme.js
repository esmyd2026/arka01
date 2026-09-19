import { Preferences } from '@capacitor/preferences';
const THEME_KEY = 'arka01_theme';
export function applyTheme(theme) { const selected = theme === 'dark' ? 'dark' : 'light'; document.documentElement.classList.toggle('dark', selected === 'dark'); document.documentElement.dataset.theme = selected; return selected; }
export async function initializeTheme() { const stored = await Preferences.get({ key: THEME_KEY }); return applyTheme(stored.value || 'light'); }
export async function toggleTheme() { const next = document.documentElement.classList.contains('dark') ? 'light' : 'dark'; applyTheme(next); await Preferences.set({ key: THEME_KEY, value: next }); return next; }
