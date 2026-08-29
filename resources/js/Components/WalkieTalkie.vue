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

// Bug real reportado por el usuario ("algunos no escuchan"): Safari/iOS no
// decodifica webm/opus, ni por MediaSource ni como archivo suelto — sin este
// aviso, esas personas se quedan sin escuchar NUNCA, en silencio, sin ningún
// mensaje que lo explique (ver receiveAudioChunk() y playStandaloneChunk()
// más abajo, que dependen de este mismo códec).
const audioPlaybackUnsupported = !window.MediaSource?.isTypeSupported?.('audio/webm;codecs=opus');

const isOpen = ref(false);
const showListeners = ref(false);
const showShareOptions = ref(false);
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
const floatingRadio = ref(null);
const bubblePosition = ref(null);
const draggingBubble = ref(false);
const dragOffset = { x: 0, y: 0 };
// La versión cambia cuando se modifica la posición inicial para que una
// ubicación antigua guardada no siga dejando la radio sobre los controles de
// cancelar/completar carrera.
const BUBBLE_POSITION_KEY = 'arka-radio-bubble-position-v2';
const CHANNEL_SELECTION_KEY = `arka-radio-selected-channel-${userPublicId.value}`;

function rememberedChannelPublicId() {
    try {
        return window.localStorage.getItem(CHANNEL_SELECTION_KEY);
    } catch {
        return null;
    }
}

function rememberChannel(publicId) {
    try {
        if (publicId) window.localStorage.setItem(CHANNEL_SELECTION_KEY, publicId);
        else window.localStorage.removeItem(CHANNEL_SELECTION_KEY);
    } catch {
        // La selección continúa en memoria aunque el modo privado no permita
        // conservarla al navegar o recargar.
    }
}

const floatingRadioStyle = computed(() => bubblePosition.value
    ? { left: `${bubblePosition.value.x}px`, top: `${bubblePosition.value.y}px` }
    : { right: '0.75rem', top: '38%' });

function clampBubblePosition(position) {
    const rect = floatingRadio.value?.getBoundingClientRect();
    const width = rect?.width || 128;
    const height = rect?.height || 52;

    return {
        x: Math.max(8, Math.min(window.innerWidth - width - 8, position.x)),
        y: Math.max(8, Math.min(window.innerHeight - height - 8, position.y)),
    };
}

function startBubbleDrag(event) {
    const rect = floatingRadio.value?.getBoundingClientRect();
    if (!rect) return;

    draggingBubble.value = true;
    dragOffset.x = event.clientX - rect.left;
    dragOffset.y = event.clientY - rect.top;
    event.currentTarget.setPointerCapture?.(event.pointerId);
}

function moveBubble(event) {
    if (!draggingBubble.value) return;
    bubblePosition.value = clampBubblePosition({
        x: event.clientX - dragOffset.x,
        y: event.clientY - dragOffset.y,
    });
}

function finishBubbleDrag(event) {
    if (!draggingBubble.value) return;
    draggingBubble.value = false;
    event.currentTarget.releasePointerCapture?.(event.pointerId);

    try {
        window.localStorage.setItem(BUBBLE_POSITION_KEY, JSON.stringify(bubblePosition.value));
    } catch {
        // El modo privado puede bloquear localStorage; moverla sigue funcionando
        // durante la sesión aunque el navegador no permita recordarla.
    }
}

function keepBubbleInsideViewport() {
    if (bubblePosition.value) bubblePosition.value = clampBubblePosition(bubblePosition.value);
}
const microphoneActive = ref(false);
const microphonePermissionBlocked = ref(false);
const requestingMicrophonePermission = ref(false);
const playbackBlocked = ref(false);
const reactivatingAudio = ref(false);
const playbackError = ref('');
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
let playbackAudioContext = null;
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

    // Desbloquea la reproducción DENTRO de este mismo clic — el primer audio
    // real recién llega por el socket segundos después, sin ningún gesto del
    // usuario en ese momento, y ahí Chrome/Safari lo tratan como autoplay
    // con sonido y lo bloquean en silencio. Reproducir ahora el <audio>
    // (todavía sin datos, MediaSource vacío) alcanza para que el mismo
    // elemento pueda seguir sonando más tarde cuando sí lleguen los chunks.
    // Bug real reportado por el usuario: "todo funciona pero no llega la voz".
    if (userGesture) {
        unlockAudioPlayback().then(() => {
            playbackBlocked.value = false;
            playbackError.value = '';
        }).catch(() => {
            // Si el desbloqueo preventivo falla, el aviso ofrece un segundo
            // gesto explícito y una explicación visible.
            playbackBlocked.value = true;
        });
    }

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
            rememberChannel(null);

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
        // Una selección manual manda sobre el canal personal. El estado se
        // consulta cada cinco segundos, por lo que priorizar siempre `is_owner`
        // hacía que el selector regresara solo al canal del cliente.
        const currentChannel = activeChannels.value.find((channel) => channel.public_id === config.value?.publicId);
        const rememberedChannel = activeChannels.value.find((channel) => channel.public_id === rememberedChannelPublicId());
        const ownChannel = activeChannels.value.find((channel) => channel.is_owner);
        const selected = currentChannel
            || rememberedChannel
            || ownChannel
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
        rememberChannel(selected.public_id);

        // Entrar al canal no necesita permiso de micrófono. Lo conectamos en
        // cuanto comienza la solicitud/carrera para que pueda escuchar sin
        // tener que encontrar y pulsar primero la burbuja. El navegador aún
        // puede pedir un toque para habilitar reproducción de audio, caso en
        // que se mantiene el aviso específico ya existente.
        if (!connected.value && connectionState.value !== 'connecting') {
            await nextTick();
            connectRadio();
        }
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

// Reintento manual del aviso "El navegador bloqueó el audio automático" — a
// diferencia del desbloqueo silencioso de connectRadio() (que corre solo al
// activar la radio), este botón le da al usuario algo concreto para tocar
// si el navegador volvió a suspender el audio más tarde (p. ej. tras un
// rato en segundo plano). Mismo truco: .play() llamado directo dentro del
// clic cuenta como gesto del usuario para el navegador.
async function unlockAudioPlayback() {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) throw new Error('Este navegador no permite reactivar el audio desde la página.');

    playbackAudioContext ??= new AudioContextClass();
    if (playbackAudioContext.state === 'suspended') await playbackAudioContext.resume();
    if (playbackAudioContext.state !== 'running') throw new Error('El sistema todavía mantiene bloqueado el sonido.');

    // Reproducimos una muestra silenciosa dentro del gesto del usuario. Esto
    // desbloquea el motor sin llamar play() sobre un MediaSource vacío, cuya
    // promesa puede quedar pendiente para siempre en Chrome.
    const source = playbackAudioContext.createBufferSource();
    source.buffer = playbackAudioContext.createBuffer(1, 1, playbackAudioContext.sampleRate);
    source.connect(playbackAudioContext.destination);
    source.start(0);
}

async function retryPlayback() {
    if (reactivatingAudio.value) return;

    reactivatingAudio.value = true;
    playbackError.value = '';

    try {
        await unlockAudioPlayback();

        // Solo llamamos play() si ya existe audio reproducible. Cuando aún no
        // habla nadie, el desbloqueo anterior es suficiente y el aviso puede
        // cerrarse inmediatamente.
        if (receiverAudio && receiverAudio.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
            await receiverAudio.play();
        }

        playbackBlocked.value = false;
    } catch (error) {
        playbackBlocked.value = true;
        playbackError.value = error?.message || 'No se pudo reactivar el sonido. Revise el volumen y los permisos del sitio.';
    } finally {
        reactivatingAudio.value = false;
    }
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
    if (audioPlaybackUnsupported) return;

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
        playbackError.value = '';
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

async function selectChannel(channel) {
    if (!channel || channel.public_id === config.value?.publicId) return;
    disconnectRadio();
    rememberChannel(channel.public_id);
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
    await nextTick();
    connectRadio();
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
    window.addEventListener('resize', keepBubbleInsideViewport);

    try {
        const savedPosition = JSON.parse(window.localStorage.getItem(BUBBLE_POSITION_KEY));
        if (Number.isFinite(savedPosition?.x) && Number.isFinite(savedPosition?.y)) {
            bubblePosition.value = savedPosition;
            nextTick(keepBubbleInsideViewport);
        }
    } catch {
        bubblePosition.value = null;
    }

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
    window.removeEventListener('resize', keepBubbleInsideViewport);
    stopInertiaListener?.();
    window.clearTimeout(grantTimer);
    window.clearTimeout(receiverCleanupTimer);
    window.clearTimeout(participantNoticeTimer);
    window.clearTimeout(availabilityTimer);
    playbackAudioContext?.close();
    playbackAudioContext = null;
    disconnectRadio();
});
</script>

<template>
    <div
        v-if="!isOpen && config"
        ref="floatingRadio"
        class="fixed z-[45] flex items-center gap-1.5"
        :style="floatingRadioStyle"
    >
        <button
            type="button"
            class="grid h-8 w-5 touch-none select-none place-items-center rounded-full border border-arka-text-muted/15 bg-arka-card/90 text-arka-text-muted shadow-lg backdrop-blur active:cursor-grabbing"
            :class="draggingBubble ? 'cursor-grabbing text-arka-primary' : 'cursor-grab'"
            aria-label="Mover botón de radio"
            title="Arrastra para mover la radio"
            @pointerdown.prevent="startBubbleDrag"
            @pointermove.prevent="moveBubble"
            @pointerup.prevent="finishBubbleDrag"
            @pointercancel.prevent="finishBubbleDrag"
        >
            <svg class="h-4 w-3" viewBox="0 0 12 18" fill="currentColor" aria-hidden="true">
                <circle cx="3" cy="4" r="1.2" /><circle cx="9" cy="4" r="1.2" />
                <circle cx="3" cy="9" r="1.2" /><circle cx="9" cy="9" r="1.2" />
                <circle cx="3" cy="14" r="1.2" /><circle cx="9" cy="14" r="1.2" />
            </svg>
        </button>
        <button
            v-if="!connected"
            type="button"
            class="grid h-12 w-12 place-items-center rounded-full border border-arka-primary/40 bg-arka-card/95 text-arka-primary shadow-2xl backdrop-blur transition active:scale-[0.95] disabled:opacity-60"
            :disabled="connectionState === 'connecting'"
            :aria-label="connectionState === 'connecting' ? 'Conectando radio' : 'Activar radio'"
            :title="connectionState === 'connecting' ? 'Conectando…' : 'Activar radio'"
            @click="connectRadio({ userGesture: true })"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <rect x="8" y="3" width="8" height="12" rx="4" />
                <path stroke-linecap="round" d="M5 11a7 7 0 0 0 14 0M12 18v3M9 21h6" />
            </svg>
            <span class="sr-only">{{ connectionState === 'connecting' ? 'Conectando…' : 'Activar radio' }}</span>
        </button>

        <button
            v-else
            type="button"
            class="grid h-12 w-12 touch-none select-none place-items-center rounded-full border shadow-2xl backdrop-blur transition focus:outline-none focus:ring-4 focus:ring-arka-primary/25 disabled:cursor-not-allowed"
            :class="isMeSpeaking
                ? 'scale-[0.97] border-arka-primary bg-arka-primary text-arka-base'
                : channelBusy
                    ? 'border-arka-text-muted/25 bg-arka-card/95 text-arka-text-muted'
                    : 'border-arka-primary/40 bg-arka-card/95 text-arka-primary active:scale-[0.97]'"
            :disabled="channelBusy"
            :aria-label="quickTalkLabel"
            :title="quickTalkLabel"
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
            <span class="sr-only">{{ quickTalkLabel }}</span>
        </button>

        <button
            type="button"
            class="relative grid h-9 w-9 shrink-0 place-items-center rounded-full border border-arka-primary/30 bg-arka-card/95 text-arka-primary shadow-xl backdrop-blur transition active:scale-95"
            aria-label="Abrir detalles del canal de seguridad"
            @click="openRadio"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
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
                    <p v-if="audioPlaybackUnsupported" class="mb-4 rounded-xl border border-arka-danger/25 bg-arka-danger/10 px-3 py-2 text-sm text-arka-danger">
                        Este navegador no puede reproducir el audio de la radio. Probá desde Chrome (Android o Windows) o desde una versión más reciente de tu navegador.
                    </p>
                    <div v-if="config" class="space-y-4">
                        <label v-if="activeChannels.length > 1" class="block">
                            <span class="mb-1.5 block text-xs font-medium text-arka-text-muted">Canal activo</span>
                            <select
                                :value="config.publicId"
                                class="w-full rounded-xl border border-arka-text-muted/20 bg-arka-base px-3 py-2.5 text-sm text-arka-text"
                                @change="selectChannel(activeChannels.find((channel) => channel.public_id === $event.target.value))"
                            >
                                <option v-for="channel in activeChannels" :key="channel.public_id" :value="channel.public_id">{{ channel.label }}</option>
                            </select>
                        </label>

                        <div class="rounded-2xl border border-arka-primary/20 bg-arka-primary/5 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-semibold text-arka-text">{{ config.channelName }}</p>
                                    <p class="mt-1 text-xs text-arka-text-muted">{{ config.isOwner ? 'Tu círculo de seguridad' : `Canal de ${config.owner.name}` }}</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-arka-primary/10 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-arka-primary">{{ ridePhase === 'searching' ? 'Solicitando' : 'En carrera' }}</span>
                            </div>
                            <div class="mt-3 flex items-center justify-between border-t border-arka-primary/10 pt-3">
                                <div class="flex items-center gap-2">
                                    <span class="relative flex h-2.5 w-2.5">
                                        <span v-if="isMeSpeaking" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-arka-primary opacity-60"></span>
                                        <span class="relative inline-flex h-2.5 w-2.5 rounded-full" :class="connected ? 'bg-arka-primary' : 'bg-arka-text-muted'"></span>
                                    </span>
                                    <p class="text-sm font-semibold text-arka-text">{{ statusLabel }}</p>
                                </div>
                                <button type="button" class="text-xs font-semibold text-arka-primary" @click="showListeners = !showListeners">
                                    {{ connected ? `${onlineParticipants.length} conectado(s)` : 'Sin conectar' }}
                                </button>
                            </div>
                        </div>

                        <button v-if="!connected" type="button" class="min-h-14 w-full rounded-xl bg-arka-primary px-4 text-sm font-bold text-arka-base shadow-lg disabled:opacity-60" :disabled="connectionState === 'connecting'" @click="connectRadio({ userGesture: true })">
                            {{ connectionState === 'connecting' ? 'Conectando…' : 'Entrar y escuchar' }}
                        </button>

                        <div v-else class="flex flex-col items-center py-1">
                            <button
                                type="button"
                                class="grid h-40 w-40 touch-none select-none place-items-center rounded-full border-4 text-center shadow-xl transition duration-100 focus:outline-none focus:ring-4 focus:ring-arka-primary/25"
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
                            <p class="mt-3 text-center text-xs text-arka-text-muted">Mantén presionado para hablar · Suelta para escuchar</p>
                        </div>

                        <div v-if="showListeners" class="rounded-xl border border-arka-text-muted/15 bg-arka-base/60 p-3">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold text-arka-text">Personas conectadas</p>
                                <button type="button" class="text-xs text-arka-text-muted" @click="showListeners = false">Ocultar</button>
                            </div>
                            <p v-if="!connected" class="mt-2 text-xs text-arka-text-muted">Entra al canal para consultar la presencia.</p>
                            <p v-else-if="onlineParticipants.length === 1" class="mt-2 text-xs text-arka-text-muted">Solo tú estás conectado por ahora.</p>
                            <div v-else class="mt-3 grid gap-2">
                                <div v-for="participant in onlineParticipants" :key="participant.id" class="flex items-center gap-2 text-sm text-arka-text-muted">
                                    <span class="h-2 w-2 rounded-full bg-arka-primary"></span>
                                    <span>{{ participant.name }}<template v-if="participant.id === userPublicId"> · tú</template></span>
                                </div>
                            </div>
                        </div>

                        <div v-if="config.isOwner && config.inviteUrl" class="border-t border-arka-text-muted/10 pt-3">
                            <button type="button" class="flex min-h-11 w-full items-center justify-between text-sm font-semibold text-arka-primary" @click="showShareOptions = !showShareOptions">
                                <span>Invitar a mi círculo</span><span aria-hidden="true">{{ showShareOptions ? '−' : '+' }}</span>
                            </button>
                            <div v-if="showShareOptions" class="grid grid-cols-2 gap-2 pt-2">
                                <button type="button" class="min-h-11 rounded-xl border border-arka-primary/40 px-3 text-xs font-semibold text-arka-primary" @click="shareChannelByWhatsApp">WhatsApp</button>
                                <button type="button" class="min-h-11 rounded-xl border border-arka-text-muted/20 px-3 text-xs font-semibold text-arka-text" @click="shareChannel">Otra aplicación</button>
                            </div>
                        </div>

                        <div v-if="playbackBlocked" class="rounded-xl border border-arka-warning/20 bg-arka-warning/10 px-3 py-2 text-sm text-arka-warning">
                            <div class="flex items-center justify-between gap-3">
                                <span>El navegador bloqueó el audio automático.</span>
                                <button type="button" class="shrink-0 rounded-lg border border-arka-warning/40 px-3 py-1.5 text-xs font-bold disabled:opacity-60" :disabled="reactivatingAudio" @click="retryPlayback">
                                    {{ reactivatingAudio ? 'Activando…' : 'Reactivar audio' }}
                                </button>
                            </div>
                            <p v-if="playbackError" class="mt-2 border-t border-arka-warning/20 pt-2 text-xs leading-4">{{ playbackError }}</p>
                        </div>
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
