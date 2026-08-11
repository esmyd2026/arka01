<script setup>
import { onMounted, ref, watch } from 'vue';
import QRCode from 'qrcode';

// Código QR con el logo de Arka01 en el centro (pedido explícito del
// usuario, con una captura de referencia) — mismo paquete `qrcode` que ya
// usa el código de invitación de flota (Driver/Profile.vue), acá con el
// logo real de la app superpuesto encima.
const props = defineProps({
    url: { type: String, required: true },
    caption: { type: String, default: 'Escanea para ver mi perfil en Arka01' },
});

const SIZE = 220;
const canvas = ref(null);

async function render() {
    if (!canvas.value || !props.url) return;

    // Nivel de corrección de errores alto ('H', tolera hasta ~30% tapado) —
    // imprescindible acá porque el logo cubre el centro; con un nivel más
    // bajo el código dejaría de poder leerse.
    await QRCode.toCanvas(canvas.value, props.url, {
        width: SIZE,
        margin: 1,
        errorCorrectionLevel: 'H',
        color: { dark: '#0a0f0c', light: '#ffffff' },
    });

    const ctx = canvas.value.getContext('2d');
    const logo = new Image();
    logo.src = '/icons/icon.svg';
    await new Promise((resolve) => {
        logo.onload = resolve;
        logo.onerror = resolve;
    });

    const logoSize = SIZE * 0.22;
    const pos = (SIZE - logoSize) / 2;
    // Marco blanco detrás del logo para que se recorte limpio contra el
    // código, en vez de superponerse directo a los módulos oscuros.
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(pos - 6, pos - 6, logoSize + 12, logoSize + 12);
    if (logo.complete && logo.naturalWidth) {
        ctx.drawImage(logo, pos, pos, logoSize, logoSize);
    }
}

onMounted(render);
watch(() => props.url, render);
</script>

<template>
    <div class="inline-flex flex-col items-center gap-3 p-5 rounded-arka" style="background-color: #0a0f0c">
        <canvas ref="canvas"></canvas>
        <p class="text-xs text-arka-text-muted text-center max-w-[200px]">{{ caption }}</p>
    </div>
</template>
