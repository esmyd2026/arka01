<script setup>
import { ref } from 'vue';
import { toggleTheme } from '../services/theme';
const dark = ref(document.documentElement.classList.contains('dark'));
async function changeTheme() { dark.value = (await toggleTheme()) === 'dark'; }
</script>
<template><button class="theme-toggle" type="button" :aria-label="dark ? 'Usar tema claro' : 'Usar tema oscuro'" @click="changeTheme"><svg v-if="dark" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg><svg v-else viewBox="0 0 24 24"><path d="M20 15.1A8.2 8.2 0 0 1 8.9 4a8.5 8.5 0 1 0 11.1 11.1Z"/></svg></button></template>
<style scoped>.theme-toggle{width:2.25rem;height:2.25rem;display:grid;place-items:center;border:0;border-radius:50%;background:var(--arka-primary-soft);color:var(--arka-primary)}svg{width:1.2rem;height:1.2rem;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}</style>
