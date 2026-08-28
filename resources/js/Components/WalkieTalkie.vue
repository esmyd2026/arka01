<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { playCabinChime } from '@/Utils/liveAlert';

const page = usePage();
const props = defineProps({
    hideBottomNav: {
        type: Boolean,
        default: false,
    },
});
const user = computed(() => page.props.auth.user);
const userPublicId = computed(() => user.value.public_id || String(user.value.id));
const identity = computed(() => {
    const code = user.value.member_code || userPublicId.value.slice(0, 6).toUpperCase();
    return `${user.value.full_name || user.value.name} · #${code}`;
});

const radioUrl = String(import.meta.env.VITE_RADIO_URL || window.location.origin).replace(/\/$/, '');
const socketPath = String(import.meta.env.VITE_RADIO_SOCKET_PATH || '/radio/socket.io').replace(/\/$/, '');
const authEndpoint = String(import.meta.env.VITE_RADIO_AUTH_ENDPOINT || '').trim();
const statusEndpoint = String(import.meta.env.VITE_RADIO_STATUS_ENDPOINT || '/radio/status').trim();

const isOpen = ref(false);
const config = ref(null);
const activeChannels = ref([]);
const onlineParticipants = ref([]);
const ridePhase = ref(null);
const participantNotice = ref('');
const connectionState = ref('disconnected');
const speaker = ref(null);
const speakerPublicId = ref(null);
const holdingButton = ref(false);
const requestingTurn = ref(false);
const microphoneActive = ref(false);
const microphonePermissionBlocked = ref(false);
const requestingMicrophonePermission = ref(false);
const playbackBlocked = ref(false);
const errorMessage = ref('');

let socket = null;
let mediaRecorder = null;
let microphoneStream = null;
let grantTimer = null;
let receiverAudio = null;
let receiverUrl = null;
let receiverMediaSource = null;
let receiverSourceBuffer = null;
let receiverQueue = [];
let receiverCleanupTimer = null;
let participantNoticeTimer = null;
let availabilityTimer = null;
let stopInertiaListener = null;
let checkingAvailability = false;
let componentMounted = false;

const connected = computed(() => connectionState.value === 'connected');
const isMeSpeaking = computed(() => speakerPublicId.value
    ? speakerPublicId.value === userPublicId.value
    : speaker.value === identity.value);
const channelBusy = computed(() => Boolean(speaker.value) && !isMeSpeaking.value);
const canTransmit = computed(() => connected.value && config.value && !channelBusy.value);

const statusLabel = computed(() => {
    if (isMeSpeaking.value) return 'Transmitiendo en vivo';
    if (channelBusy.value) return `${speaker.value} está hablando`;
    if (connectionState.value === 'connecting') return 'Conectando al canal…';
    if (connectionState.value === 'error') return 'Radio sin conexión';
    if (connected.value) return 'Canal libre';
    return 'Radio desconectada';
});

const buttonLabel = computed(() => {
    if (isMeSpeaking.value) return 'SUELTA PARA TERMINAR';
    if (requestingTurn.value) return 'SOLICITANDO TURNO…';
    if (channelBusy.value) return 'ESPERE SU TURNO';
    if (!connected.value) return 'CONECTE LA RADIO';
    return 'MANTENGA PRESIONADO';
});

const quickTalkLabel = computed(() => {
    if (isMeSpeaking.value) return 'Suelta para terminar';
    if (requestingTurn.value) return 'Solicitando turno…';
    if (channelBusy.value) return 'Canal ocupado';
    if (connectionState.value === 'connecting') return 'Conectando…';
    if (!connected.value) return 'Radio desconectada';
    return 'Mantén para hablar';
});

let socketClientPromise;

function loadSocketClient() {
    if (window.io) return Promise.resolve(window.io);
    if (socketClientPromise) return socketClientPromise;

    socketClientPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = `${radioUrl}${socketPath}/socket.io.js`;
        script.async = true;
        script.onload = () => (window.io ? resolve(window.io) : reject(new Error('El servidor no entregó el cliente de radio.')));
        script.onerror = () => reject(new Error('No se pudo cargar el servicio de radio.'));
        document.head.appendChild(script);
    });

    return socketClientPromise;
}

async function fetchRadioToken() {
    if (!authEndpoint) return null;

    const response = await axios.post(authEndpoint, {
        channel_public_id: config.value?.publicId,
    });

    if (response.data?.room_id !== config.value?.roomId) {
        throw new Error('El canal activo cambió. Actualizando la radio…');
    }

    return response.data?.token || null;
}

async function connectRadio({ userGesture = false } = {}) {
    if (!config.value || connectionState.value === 'connecting' || connected.value) return;

    if (socket && !socket.connected) {
        connectionState.value = 'connecting';
        socket.connect();
        return;
    }

    errorMessage.value = '';
    playbackBlocked.value = false;
    connectionState.value = 'connecting';

    try {
        const [io, token] = await Promise.all([loadSocketClient(), fetchRadioToken()]);

        socket = io(radioUrl, {
            path: socketPath,
            transports: ['websocket'],
            reconnection: true,
            reconnectionDelay: 800,
            reconnectionDelayMax: 5000,
            auth: token ? { token } : undefined,
        });

        socket.on('connect', () => {
            connectionState.value = 'connected';
            errorMessage.value = '';
            socket.emit('join-channel', {
                channel: config.value.roomId,
                username: identity.value,
            });
        });

        socket.on('disconnect', () => {
            connectionState.value = 'disconnected';
            speaker.value = null;
            speakerPublicId.value = null;
            stopCapture();
        });

        socket.on('connect_error', (error) => {
            connectionState.value = 'error';
            errorMessage.value = error?.message || 'No se pudo conectar con la radio.';
            stopCapture();

            // Un token vencido no debe reutilizarse en cada reconexión. Se
            // descarta ese socket para que el siguiente intento solicite una
            // credencial nueva al backend.
            if (error?.data?.code === 'RADIO_AUTH_FAILED') {
                socket?.disconnect();
                socket = null;
            }
        });

        socket.on('speaker-changed', handleSpeakerChanged);
        socket.on('participant-joined', handleParticipantJoined);
        socket.on('presence-changed', (participants) => {
            onlineParticipants.value = Array.isArray(participants) ? participants : [];
        });
        socket.on('mic-denied', denyMicrophoneTurn);
        socket.on('audio-receive', receiveAudioChunk);

        if (userGesture) await nextTick();
    } catch (error) {
        if (!window.io) socketClientPromise = null;
        connectionState.value = 'error';
        errorMessage.value = error?.message || 'No se pudo iniciar la radio.';
    }
}

function disconnectRadio() {
    releaseTransmission();
    socket?.disconnect();
    socket = null;
    connectionState.value = 'disconnected';
    speaker.value = null;
    speakerPublicId.value = null;
    onlineParticipants.value = [];
    resetReceiver();
}

function handleSpeakerChanged(participant) {
    const participantIsObject = participant && typeof participant === 'object';
    speaker.value = participantIsObject ? (participant.name || null) : (participant || null);
    speakerPublicId.value = participantIsObject ? (participant.id || null) : null;

    if (isMeSpeaking.value && requestingTurn.value) {
        window.clearTimeout(grantTimer);
        requestingTurn.value = false;

        if (holdingButton.value) startCapture();
        else socket?.emit('release-mic', config.value.roomId);
    }

    if (speaker.value && !isMeSpeaking.value) prepareReceiver();
    if (!speaker.value) scheduleReceiverCleanup();
    if (!isMeSpeaking.value && microphoneActive.value) stopCapture();
}

async function showParticipantSystemNotification(message) {
    if (document.visibilityState === 'visible' || window.Notification?.permission !== 'granted') return;

    try {
        if (navigator.serviceWorker) {
            const registration = await navigator.serviceWorker.getRegistration();
            if (!registration) throw new Error('No hay un service worker activo.');
            await registration.showNotification('Canal de seguridad · Arka01', {
                body: message,
                icon: '/icons/icon-192x192.png',
                tag: 'ride-radio-participant',
            });
            return;
        }

        new Notification('Canal de seguridad · Arka01', { body: message });
    } catch {
        // El aviso dentro de la app sigue visible aunque el sistema operativo
        // no permita mostrar una notificación propia.
    }
}

function handleParticipantJoined(participant) {
    if (!participant || participant.id === userPublicId.value) return;

    const message = `${participant.name} se conectó a ${config.value?.channelName || 'tu canal de seguridad'}.`;
    participantNotice.value = message;
    window.clearTimeout(participantNoticeTimer);
    participantNoticeTimer = window.setTimeout(() => {
        participantNotice.value = '';
    }, 7000);

    playCabinChime();
    showParticipantSystemNotification(message);
}

function scheduleAvailabilityCheck() {
    window.clearTimeout(availabilityTimer);
    if (!componentMounted) return;

    availabilityTimer = window.setTimeout(
        syncRadioAvailability,
        config.value ? 5000 : 30000,
    );
}

async function syncRadioAvailability() {
    if (checkingAvailability) return;
    checkingAvailability = true;

    try {
        const response = await axios.get(statusEndpoint, {
            headers: { Accept: 'application/json' },
        });
        const context = response.data;

        if (!context?.enabled) {
            const radioWasAvailable = Boolean(config.value);
            if (radioWasAvailable) disconnectRadio();
            config.value = null;
            activeChannels.value = [];
            ridePhase.value = null;
            isOpen.value = false;

            if (radioWasAvailable) {
                participantNotice.value = 'La radio se cerró porque la solicitud o la carrera terminó.';
                window.clearTimeout(participantNoticeTimer);
                participantNoticeTimer = window.setTimeout(() => {
                    participantNotice.value = '';
                }, 5000);
            }
            return;
        }

        activeChannels.value = Array.isArray(context.channels) ? context.channels : [context];
        const selected = activeChannels.value.find((channel) => channel.public_id === config.value?.publicId)
            || activeChannels.value[0];

        if (config.value?.roomId && config.value.roomId !== selected.room_id) {
            disconnectRadio();
        }

        config.value = {
            publicId: selected.public_id,
            roomId: selected.room_id,
            channelName: selected.label || 'Canal de seguridad',
            owner: selected.owner,
            isOwner: selected.is_owner,
            inviteUrl: selected.invite_url,
            memberCount: selected.member_count || 0,
            members: selected.members || [],
        };
        ridePhase.value = selected.phase;
    } catch (error) {
        // Una falla temporal de red no debe esconder una radio que ya estaba
        // autorizada. El siguiente control vuelve a conciliar el estado.
        if (!config.value && error?.response?.status === 403) return;
    } finally {
        checkingAvailability = false;
        scheduleAvailabilityCheck();
    }
}

function refreshAvailabilityWhenVisible() {
    if (document.visibilityState === 'visible') syncRadioAvailability();
}

function denyMicrophoneTurn() {
    requestingTurn.value = false;
    holdingButton.value = false;
    stopCapture();
    errorMessage.value = 'Otra persona tomó el turno primero. Inténtelo cuando el canal quede libre.';
}

async function requestTransmission(event) {
    if (event?.pointerId !== undefined) event.currentTarget?.setPointerCapture?.(event.pointerId);
    if (!canTransmit.value || holdingButton.value) return;

    holdingButton.value = true;
    requestingTurn.value = true;
    errorMessage.value = '';
    socket.emit('request-mic', config.value.roomId);

    window.clearTimeout(grantTimer);
    grantTimer = window.setTimeout(() => {
        if (!requestingTurn.value) return;
        requestingTurn.value = false;
        holdingButton.value = false;
        errorMessage.value = 'El repetidor no confirmó el turno. Revise la conexión e inténtelo otra vez.';
        socket?.emit('release-mic', config.value.roomId);
    }, 3500);
}

function releaseTransmission() {
    holdingButton.value = false;
    requestingTurn.value = false;
    window.clearTimeout(grantTimer);
    stopCapture();

    if (socket?.connected && config.value) socket.emit('release-mic', config.value.roomId);
}

function handleKeyDown(event) {
    if ((event.code === 'Space' || event.code === 'Enter') && !event.repeat) {
        event.preventDefault();
        requestTransmission();
    }
}

function handleKeyUp(event) {
    if (event.code === 'Space' || event.code === 'Enter') {
        event.preventDefault();
        releaseTransmission();
    }
}

function supportedMimeType() {
    return [
        'audio/webm;codecs=opus',
        'audio/webm',
    ].find((type) => window.MediaRecorder?.isTypeSupported?.(type));
}

async function startCapture() {
    if (!holdingButton.value || microphoneActive.value) return;
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
        errorMessage.value = 'Este navegador no permite usar el micrófono como radio.';
        releaseTransmission();
        return;
    }

    const mimeType = supportedMimeType();
    if (!mimeType) {
        errorMessage.value = 'Este navegador no admite audio Opus para la radio web.';
        releaseTransmission();
        return;
    }

    try {
        microphoneStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
                channelCount: 1,
            },
        });

        if (!holdingButton.value || !isMeSpeaking.value) {
            microphoneStream.getTracks().forEach((track) => track.stop());
            microphoneStream = null;
            releaseTransmission();
            return;
        }

        mediaRecorder = new MediaRecorder(microphoneStream, {
            mimeType,
            audioBitsPerSecond: 24000,
        });
        mediaRecorder.ondataavailable = async (event) => {
            if (!event.data.size || !isMeSpeaking.value || !socket?.connected) return;

            socket.emit('audio-stream', {
                channel: config.value.roomId,
                audioBlob: await event.data.arrayBuffer(),
            });
        };
        mediaRecorder.onerror = () => {
            errorMessage.value = 'La captura del micrófono se interrumpió.';
            releaseTransmission();
        };
        mediaRecorder.start(250);
        microphoneActive.value = true;
        microphonePermissionBlocked.value = false;
        navigator.vibrate?.(35);
    } catch (error) {
        const permissionWasDenied = error?.name === 'NotAllowedError' || error?.name === 'SecurityError';
        microphonePermissionBlocked.value = permissionWasDenied;
        errorMessage.value = permissionWasDenied
            ? ''
            : 'No se pudo abrir el micrófono de este dispositivo.';
        // Si habló desde el acceso rápido, abrimos los detalles únicamente
        // cuando hay algo que necesita resolver.
        isOpen.value = true;
        releaseTransmission();
    }
}

async function requestMicrophonePermission() {
    if (!navigator.mediaDevices?.getUserMedia) {
        errorMessage.value = 'Este navegador no permite solicitar acceso al micrófono.';
        return;
    }

    requestingMicrophonePermission.value = true;
    errorMessage.value = '';

    try {
        // Chrome mantiene el permiso anterior en la pestaña cuando el usuario
        // lo cambia desde el panel del sitio. Si ya figura como concedido, la
        // única forma de aplicarlo al documento actual es recargarlo.
        if (navigator.permissions?.query) {
            const permission = await navigator.permissions.query({ name: 'microphone' });
            if (permission.state === 'granted') {
                window.location.reload();
                return;
            }
        }

        // La solicitud debe originarse en este clic para que el navegador pueda
        // mostrar nuevamente su diálogo nativo de permisos.
        const permissionStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        permissionStream.getTracks().forEach((track) => track.stop());
        microphonePermissionBlocked.value = false;
    } catch (error) {
        microphonePermissionBlocked.value = true;
    } finally {
        requestingMicrophonePermission.value = false;
    }
}

function reloadToApplyMicrophonePermission() {
    window.location.reload();
}

function stopCapture() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
    microphoneStream?.getTracks().forEach((track) => track.stop());
    mediaRecorder = null;
    microphoneStream = null;
    microphoneActive.value = false;
}

function toArrayBuffer(payload) {
    if (payload instanceof ArrayBuffer) return payload;
    if (ArrayBuffer.isView(payload)) {
        return payload.buffer.slice(payload.byteOffset, payload.byteOffset + payload.byteLength);
    }
    if (payload?.type === 'Buffer' && Array.isArray(payload.data)) {
        return new Uint8Array(payload.data).buffer;
    }
    return null;
}

function prepareReceiver() {
    window.clearTimeout(receiverCleanupTimer);
    if (receiverMediaSource || receiverAudio) return;

    if (!window.MediaSource?.isTypeSupported?.('audio/webm;codecs=opus')) return;

    receiverQueue = [];
    receiverMediaSource = new MediaSource();
    receiverAudio = new Audio();
    receiverAudio.autoplay = true;
    receiverUrl = URL.createObjectURL(receiverMediaSource);
    receiverAudio.src = receiverUrl;
    receiverMediaSource.addEventListener('sourceopen', () => {
        if (!receiverMediaSource || receiverMediaSource.readyState !== 'open') return;
        receiverSourceBuffer = receiverMediaSource.addSourceBuffer('audio/webm;codecs=opus');
        receiverSourceBuffer.mode = 'sequence';
        receiverSourceBuffer.addEventListener('updateend', appendNextReceiverChunk);
        appendNextReceiverChunk();
    }, { once: true });
}

function receiveAudioChunk(payload) {
    const buffer = toArrayBuffer(payload);
    if (!buffer) return;

    prepareReceiver();
    if (!receiverMediaSource) {
        playStandaloneChunk(buffer);
        return;
    }

    receiverQueue.push(buffer);
    appendNextReceiverChunk();
    receiverAudio.play().then(() => {
        playbackBlocked.value = false;
    }).catch(() => {
        playbackBlocked.value = true;
    });
}

function appendNextReceiverChunk() {
    if (!receiverSourceBuffer || receiverSourceBuffer.updating || !receiverQueue.length) return;
    if (receiverMediaSource?.readyState !== 'open') return;

    try {
        receiverSourceBuffer.appendBuffer(receiverQueue.shift());
    } catch {
        resetReceiver();
    }
}

function playStandaloneChunk(buffer) {
    const url = URL.createObjectURL(new Blob([buffer], { type: 'audio/webm;codecs=opus' }));
    const audio = new Audio(url);
    audio.play().catch(() => {
        playbackBlocked.value = true;
    });
    audio.addEventListener('ended', () => URL.revokeObjectURL(url), { once: true });
    audio.addEventListener('error', () => URL.revokeObjectURL(url), { once: true });
}

function scheduleReceiverCleanup() {
    window.clearTimeout(receiverCleanupTimer);
    receiverCleanupTimer = window.setTimeout(resetReceiver, 1500);
}

function resetReceiver() {
    window.clearTimeout(receiverCleanupTimer);
    receiverAudio?.pause();
    if (receiverUrl) URL.revokeObjectURL(receiverUrl);
    receiverQueue = [];
    receiverSourceBuffer = null;
    receiverMediaSource = null;
    receiverAudio = null;
    receiverUrl = null;
}

function openRadio() {
    isOpen.value = true;
}

function selectChannel(channel) {
    if (!channel || channel.public_id === config.value?.publicId) return;
    disconnectRadio();
    config.value = {
        publicId: channel.public_id,
        roomId: channel.room_id,
        channelName: channel.label || 'Canal de seguridad',
        owner: channel.owner,
        isOwner: channel.is_owner,
        inviteUrl: channel.invite_url,
        memberCount: channel.member_count || 0,
        members: channel.members || [],
    };
    ridePhase.value = channel.phase;
}

async function shareChannel() {
    if (!config.value?.inviteUrl) return;
    const share = {
        title: `Canal de seguridad de ${config.value.owner?.name || 'Arka01'}`,
        text: `${config.value.owner?.name || 'Un contacto'} te invita a su canal privado de seguridad en Arka01.`,
        url: config.value.inviteUrl,
    };

    try {
        if (navigator.share) await navigator.share(share);
        else {
            await navigator.clipboard.writeText(`${share.text} ${share.url}`);
            participantNotice.value = 'Enlace del canal copiado.';
        }
    } catch (error) {
        if (error?.name !== 'AbortError') errorMessage.value = 'No se pudo compartir el canal.';
    }
}

function shareChannelByWhatsApp() {
    if (!config.value?.inviteUrl) return;
    const text = `${config.value.owner?.name || 'Un contacto'} te invita a su canal privado de seguridad en Arka01: ${config.value.inviteUrl}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank', 'noopener,noreferrer');
}

onMounted(() => {
    componentMounted = true;
    syncRadioAvailability();
    stopInertiaListener = router.on('finish', syncRadioAvailability);
    window.addEventListener('blur', releaseTransmission);
    window.addEventListener('focus', syncRadioAvailability);
    document.addEventListener('visibilitychange', refreshAvailabilityWhenVisible);

    // Viene de "Ir al canal de radio" en Radio/Invitation.vue: el enlace de
    // invitación no sabe abrir el bottom sheet directamente (vive en otra
    // página), así que llega por querystring. Se abre y se limpia la URL
    // para que un refresh no reabra el panel solo.
    if (new URLSearchParams(window.location.search).has('abrir_radio')) {
        isOpen.value = true;
        window.history.replaceState(null, '', window.location.pathname);
    }
});

onBeforeUnmount(() => {
    componentMounted = false;
    window.removeEventListener('blur', releaseTransmission);
    window.removeEventListener('focus', syncRadioAvailability);
    document.removeEventListener('visibilitychange', refreshAvailabilityWhenVisible);
    stopInertiaListener?.();
    window.clearTimeout(grantTimer);
    window.clearTimeout(receiverCleanupTimer);
    window.clearTimeout(participantNoticeTimer);
    window.clearTimeout(availabilityTimer);
    disconnectRadio();
});
</script>

<template>
    <div
        v-if="!isOpen && config"
        class="fixed right-3 z-[45] flex items-center gap-2 sm:bottom-5"
        :class="props.hideBottomNav ? 'bottom-4' : 'bottom-24'"
    >
        <button
            v-if="!connected"
            type="button"
            class="flex min-h-14 items-center gap-2 rounded-full border border-arka-primary/40 bg-arka-card/95 px-4 text-arka-primary shadow-2xl backdrop-blur transition active:scale-[0.97] disabled:opacity-60"
            :disabled="connectionState === 'connecting'"
            @click="connectRadio({ userGesture: true })"
        >
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <rect x="8" y="3" width="8" height="12" rx="4" />
                <path stroke-linecap="round" d="M5 11a7 7 0 0 0 14 0M12 18v3M9 21h6" />
            </svg>
            <span class="text-xs font-bold">{{ connectionState === 'connecting' ? 'Conectando…' : 'Activar radio' }}</span>
        </button>

        <button
            v-else
            type="button"
            class="flex min-h-14 touch-none select-none items-center gap-2 rounded-full border px-4 shadow-2xl backdrop-blur transition focus:outline-none focus:ring-4 focus:ring-arka-primary/25 disabled:cursor-not-allowed"
            :class="isMeSpeaking
                ? 'scale-[0.97] border-arka-primary bg-arka-primary text-arka-base'
                : channelBusy
                    ? 'border-arka-text-muted/25 bg-arka-card/95 text-arka-text-muted'
                    : 'border-arka-primary/40 bg-arka-card/95 text-arka-primary active:scale-[0.97]'"
            :disabled="channelBusy"
            :aria-label="quickTalkLabel"
            @pointerdown.prevent="requestTransmission"
            @pointerup.prevent="releaseTransmission"
            @pointercancel.prevent="releaseTransmission"
            @keydown="handleKeyDown"
            @keyup="handleKeyUp"
        >
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <rect x="8" y="3" width="8" height="12" rx="4" />
                <path stroke-linecap="round" d="M5 11a7 7 0 0 0 14 0M12 18v3M9 21h6" />
            </svg>
            <span class="text-xs font-bold">{{ quickTalkLabel }}</span>
        </button>

        <button
            type="button"
            class="relative grid h-11 w-11 shrink-0 place-items-center rounded-full border border-arka-primary/30 bg-arka-card/95 text-arka-primary shadow-xl backdrop-blur transition active:scale-95"
            aria-label="Abrir detalles del canal de seguridad"
            @click="openRadio"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <rect x="7" y="5" width="10" height="15" rx="2" />
                <path stroke-linecap="round" d="M10 9h4M10 12h4M9 3h6" />
                <circle cx="12" cy="16" r="1.5" />
            </svg>
            <span v-if="connected" class="absolute right-0 top-0 h-2.5 w-2.5 rounded-full bg-arka-primary ring-2 ring-arka-card"></span>
        </button>
    </div>

    <Teleport to="body">
        <div v-if="isOpen" class="fixed inset-0 z-[1900] flex items-end justify-center bg-black/70 p-0 sm:items-center sm:p-4" @click.self="isOpen = false">
            <section class="w-full max-w-md rounded-t-3xl border border-arka-text-muted/15 bg-arka-card shadow-2xl sm:rounded-3xl" role="dialog" aria-modal="true" aria-labelledby="radio-title">
                <header class="flex items-center justify-between border-b border-arka-text-muted/10 px-5 py-4">
                    <div class="flex items-center gap-3">
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-arka-primary/10 text-arka-primary">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <rect x="7" y="5" width="10" height="15" rx="2" />
                                <path stroke-linecap="round" d="M10 9h4M10 12h4M9 3h6" />
                                <circle cx="12" cy="16" r="1.5" />
                            </svg>
                        </span>
                        <div>
                            <h2 id="radio-title" class="font-semibold text-arka-text">Canal de seguridad</h2>
                            <p class="text-xs text-arka-text-muted">Su círculo durante la carrera</p>
                        </div>
                    </div>
                    <button type="button" class="grid h-10 w-10 place-items-center rounded-full text-arka-text-muted hover:bg-arka-base hover:text-arka-text" aria-label="Cerrar radio" @click="isOpen = false">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </header>

                <div class="max-h-[calc(100dvh-6rem)] overflow-y-auto p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]">
                    <div v-if="config" class="space-y-4">
                        <div v-if="activeChannels.length > 1" class="space-y-2">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-arka-text-muted">Canales activos</p>
                            <div class="grid gap-2">
                                <button
                                    v-for="channel in activeChannels"
                                    :key="channel.public_id"
                                    type="button"
                                    class="flex items-center justify-between rounded-xl border px-3 py-2 text-left text-sm"
                                    :class="channel.public_id === config.publicId ? 'border-arka-primary bg-arka-primary/10 text-arka-text' : 'border-arka-text-muted/15 bg-arka-base text-arka-text-muted'"
                                    @click="selectChannel(channel)"
                                >
                                    <span class="truncate font-semibold">{{ channel.label }}</span>
                                    <span class="ml-3 shrink-0 text-xs">{{ channel.owner.name }}</span>
                                </button>
                            </div>
                        </div>

                        <div class="flex items-start justify-between gap-3 rounded-2xl border border-arka-text-muted/10 bg-arka-base/70 p-4">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-arka-primary">{{ config.isOwner ? 'Mi canal principal' : 'Canal compartido conmigo' }}</p>
                                <p class="mt-1 truncate font-semibold text-arka-text">{{ config.channelName }}</p>
                                <p class="mt-1 text-xs text-arka-text-muted">{{ config.owner.name }} · {{ config.owner.role }}</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-arka-primary/10 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-arka-primary">{{ ridePhase === 'searching' ? 'Solicitando' : 'En carrera' }}</span>
                        </div>

                        <div class="rounded-2xl border border-arka-text-muted/10 bg-arka-base/60 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-semibold text-arka-text">Escuchando ahora</p>
                                <span class="rounded-full bg-arka-primary/10 px-2.5 py-1 text-xs font-bold text-arka-primary">{{ onlineParticipants.length }}</span>
                            </div>
                            <p v-if="!connected" class="mt-2 text-xs text-arka-text-muted">Active la radio para ver quién está conectado.</p>
                            <p v-else-if="onlineParticipants.length === 1" class="mt-2 text-xs text-arka-text-muted">Solo usted está conectado por ahora.</p>
                            <div v-else class="mt-3 flex flex-wrap gap-2">
                                <span v-for="participant in onlineParticipants" :key="participant.id" class="rounded-full border border-arka-text-muted/15 px-2.5 py-1 text-xs text-arka-text-muted">
                                    {{ participant.name }}<template v-if="participant.id === userPublicId"> · tú</template>
                                </span>
                            </div>
                            <p class="mt-3 text-[11px] text-arka-text-muted">{{ config.memberCount }} integrante(s) autorizado(s) en el círculo.</p>
                        </div>

                        <div v-if="config.isOwner && config.inviteUrl" class="grid grid-cols-2 gap-2">
                            <button type="button" class="min-h-11 rounded-xl border border-arka-primary/40 px-3 text-xs font-semibold text-arka-primary" @click="shareChannelByWhatsApp">Compartir por WhatsApp</button>
                            <button type="button" class="min-h-11 rounded-xl border border-arka-text-muted/20 px-3 text-xs font-semibold text-arka-text" @click="shareChannel">Compartir por otra red</button>
                        </div>

                        <div class="flex items-center gap-3 rounded-2xl border px-4 py-3" :class="isMeSpeaking ? 'border-arka-primary/40 bg-arka-primary/10' : channelBusy ? 'border-arka-text-muted/20 bg-arka-base' : 'border-arka-primary/20 bg-arka-primary/5'">
                            <span class="relative flex h-3 w-3 shrink-0">
                                <span v-if="isMeSpeaking" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-arka-primary opacity-60"></span>
                                <span class="relative inline-flex h-3 w-3 rounded-full" :class="isMeSpeaking ? 'bg-arka-primary' : channelBusy ? 'bg-arka-text-muted' : connected ? 'bg-arka-primary' : 'bg-arka-danger'"></span>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-arka-text">{{ statusLabel }}</p>
                                <p class="text-xs text-arka-text-muted">{{ connected ? 'Audio en vivo · sin grabaciones' : 'Entre al canal para escuchar y hablar' }}</p>
                            </div>
                        </div>

                        <button v-if="!connected" type="button" class="min-h-12 w-full rounded-xl bg-arka-primary px-4 text-sm font-bold text-arka-base disabled:opacity-60" :disabled="connectionState === 'connecting'" @click="connectRadio({ userGesture: true })">
                            {{ connectionState === 'connecting' ? 'Conectando…' : 'Activar radio' }}
                        </button>

                        <div v-else class="flex flex-col items-center py-1">
                            <button
                                type="button"
                                class="grid h-44 w-44 touch-none select-none place-items-center rounded-full border-4 text-center shadow-2xl transition duration-100 focus:outline-none focus:ring-4 focus:ring-arka-primary/25"
                                :class="isMeSpeaking ? 'scale-[0.97] border-arka-primary-bright bg-arka-primary text-arka-base' : channelBusy ? 'cursor-not-allowed border-arka-text-muted/20 bg-arka-base text-arka-text-muted' : 'border-arka-primary/35 bg-arka-primary/10 text-arka-primary active:scale-[0.97]'"
                                :disabled="channelBusy"
                                :aria-label="buttonLabel"
                                @pointerdown.prevent="requestTransmission"
                                @pointerup.prevent="releaseTransmission"
                                @pointercancel.prevent="releaseTransmission"
                                @keydown="handleKeyDown"
                                @keyup="handleKeyUp"
                            >
                                <span class="px-5">
                                    <svg class="mx-auto h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <rect x="8" y="3" width="8" height="12" rx="4" />
                                        <path stroke-linecap="round" d="M5 11a7 7 0 0 0 14 0M12 18v3M9 21h6" />
                                    </svg>
                                    <span class="mt-3 block text-xs font-black leading-4 tracking-wide">{{ buttonLabel }}</span>
                                </span>
                            </button>
                            <p class="mt-3 text-center text-xs text-arka-text-muted">Mantenga pulsado mientras habla. Al soltar, el canal queda libre.</p>
                        </div>

                        <p v-if="playbackBlocked" class="rounded-xl border border-arka-warning/20 bg-arka-warning/10 px-3 py-2 text-sm text-arka-warning">El navegador bloqueó el audio automático. Toque nuevamente “Radio” o revise los permisos de sonido.</p>
                        <div v-if="microphonePermissionBlocked" class="rounded-xl border border-arka-danger/25 bg-arka-danger/10 p-3 text-sm text-arka-danger">
                            <p>El micrófono está bloqueado para este sitio.</p>
                            <button
                                type="button"
                                class="mt-3 inline-flex min-h-10 items-center justify-center rounded-lg border border-arka-danger/40 bg-arka-base/40 px-4 font-semibold text-arka-danger transition hover:bg-arka-danger/10 disabled:opacity-60"
                                :disabled="requestingMicrophonePermission"
                                @click="requestMicrophonePermission"
                            >
                                {{ requestingMicrophonePermission ? 'Solicitando permiso…' : 'Activar micrófono' }}
                            </button>
                            <button
                                type="button"
                                class="ml-2 mt-3 inline-flex min-h-10 items-center justify-center px-2 text-xs font-semibold text-arka-text-muted underline underline-offset-4 hover:text-arka-text"
                                @click="reloadToApplyMicrophonePermission"
                            >
                                Recargar y aplicar permiso
                            </button>
                            <p class="mt-2 text-xs leading-4 text-arka-text-muted">Si cambió el permiso desde el icono de controles o candado, debe recargar esta página para aplicarlo.</p>
                        </div>
                        <p v-if="errorMessage" class="rounded-xl border border-arka-danger/20 bg-arka-danger/10 px-3 py-2 text-sm text-arka-danger">{{ errorMessage }}</p>

                        <div class="flex items-center justify-between border-t border-arka-text-muted/10 pt-3">
                            <button v-if="connected" type="button" class="text-xs font-semibold text-arka-text-muted hover:text-arka-text" @click="disconnectRadio">Desactivar audio</button>
                            <span v-else></span>
                            <span class="max-w-52 text-right text-[10px] leading-4 text-arka-text-muted">Se oculta cuando termina la carrera del propietario.</span>
                        </div>
                    </div>
                    <p v-else class="py-6 text-center text-sm text-arka-text-muted">
                        Este canal se activa solo cuando su propietario solicita o realiza una carrera.
                    </p>
                </div>
            </section>
        </div>
    </Teleport>

    <Teleport to="body">
        <div v-if="participantNotice" class="fixed left-1/2 top-20 z-[2000] w-[calc(100%-2rem)] max-w-sm -translate-x-1/2 rounded-2xl border border-arka-primary/25 bg-arka-card px-4 py-3 text-sm text-arka-text shadow-2xl">
            <p class="font-semibold text-arka-primary">Canal de seguridad</p>
            <p class="mt-1 leading-5">{{ participantNotice }}</p>
        </div>
    </Teleport>
</template>
