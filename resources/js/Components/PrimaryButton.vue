<template>
    <!-- Botón principal: fondo verde menta (color de marca), texto oscuro para contraste -->
    <button
        class="inline-flex items-center px-4 py-2 bg-arka-primary border border-transparent rounded-arka font-semibold text-xs text-arka-base uppercase tracking-widest hover:bg-arka-primary-bright focus:bg-arka-primary-bright active:bg-arka-primary focus:outline-none focus:ring-2 focus:ring-arka-primary focus:ring-offset-2 focus:ring-offset-arka-base transition ease-in-out duration-150 disabled:opacity-50"
    >
        <slot />
    </button>
</template>
