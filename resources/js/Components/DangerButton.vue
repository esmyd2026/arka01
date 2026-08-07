<template>
    <!-- Botón de peligro: usa el color de error de la paleta (rojo coral), no el rojo genérico de Tailwind -->
    <button
        class="inline-flex items-center px-4 py-2 bg-arka-danger border border-transparent rounded-arka font-semibold text-xs text-arka-base uppercase tracking-widest hover:opacity-90 active:opacity-100 focus:outline-none focus:ring-2 focus:ring-arka-danger focus:ring-offset-2 focus:ring-offset-arka-base transition ease-in-out duration-150"
    >
        <slot />
    </button>
</template>
