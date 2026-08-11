<script setup>
// Logotipo de la marca (sección 9.9): antes era texto tipográfico armado a
// mano; pedido explícito del usuario, ahora usa el archivo real del logo
// (fondo transparente, se ve bien tanto en tarjetas oscuras como en la base
// de la app). `size` ahora es una clase de alto (h-*), no de texto — el
// ancho se ajusta solo para no deformar la imagen.
defineProps({
    size: { type: String, default: 'h-8' },
});

// Bug real reportado por el usuario (con capturas): el archivo original que
// subió ("logo arka01 completo sin fondo.png") trae de fábrica un margen
// transparente enorme alrededor del isotipo + "Arka01" + eslogan — a las
// alturas chicas en las que vive el logo en la app (header, login, perfil
// público, etc.) ese margen se comía casi todo el alto disponible y el logo
// quedaba minúsculo o directamente invisible. `logo-arka01.png` es un
// recorte generado una sola vez (PHP GD, al margen del bounding box real de
// píxeles opacos) del mismo archivo original, sin ese margen muerto y sin el
// eslogan (que ya se repite como texto en cada pantalla donde hace falta) —
// mismo isotipo, mismo trazo, solo bien encuadrado.
const LOGO_SRC = '/img/logo-arka01.png';
</script>

<template>
    <img :src="LOGO_SRC" alt="Arka01" class="w-auto select-none" :class="size" />
</template>
