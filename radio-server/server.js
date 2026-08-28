'use strict';

require('dotenv').config();

const crypto = require('crypto');
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');

function decodeBase64Url(value) {
    return Buffer.from(value, 'base64url');
}

function verifyToken(token, sharedSecret) {
    if (typeof token !== 'string') throw new Error('TOKEN_MISSING');

    const parts = token.split('.');
    if (parts.length !== 2) throw new Error('TOKEN_INVALID');

    const [payload, encodedSignature] = parts;
    const received = decodeBase64Url(encodedSignature);
    const expected = crypto.createHmac('sha256', sharedSecret).update(payload).digest();

    if (received.length !== expected.length || !crypto.timingSafeEqual(received, expected)) {
        throw new Error('TOKEN_INVALID');
    }

    const claims = JSON.parse(decodeBase64Url(payload).toString('utf8'));
    const now = Math.floor(Date.now() / 1000);

    if (!claims.exp || claims.exp <= now) throw new Error('TOKEN_EXPIRED');
    if (!/^arka01-[a-f0-9]{64}$/.test(claims.room || '')) throw new Error('ROOM_INVALID');
    if (!claims.sub || !claims.name || !['cliente', 'conductor'].includes(claims.role)) {
        throw new Error('CLAIMS_INVALID');
    }

    return claims;
}

function createRadioServer(options = {}) {
    const sharedSecret = options.sharedSecret ?? process.env.RADIO_SHARED_SECRET ?? '';
    const allowedOrigins = new Set(options.allowedOrigins ?? String(process.env.APP_ORIGINS || '').split(',').map((origin) => origin.trim()).filter(Boolean));
    const socketPath = options.socketPath ?? process.env.SOCKET_PATH ?? '/socket.io';
    const maxPacket = Number(options.maxPacket ?? process.env.MAX_AUDIO_PACKET_BYTES ?? 65536);
    const maxTalkMs = Number(options.maxTalkMs ?? (Number(process.env.MAX_TALK_SECONDS || 60) * 1000));

    if (sharedSecret.length < 64) throw new Error('RADIO_SHARED_SECRET debe tener al menos 64 caracteres.');
    if (!allowedOrigins.size) throw new Error('APP_ORIGINS debe incluir al menos un origen permitido.');

    const app = express();
    const httpServer = http.createServer(app);
    const activeSpeakers = new Map();
    const roomParticipants = new Map();
    const io = new Server(httpServer, {
        path: socketPath,
        serveClient: true,
        transports: ['websocket'],
        // Bug real reportado por el usuario ("se cae"): con los valores por
        // defecto de Socket.IO (pingInterval 25s, pingTimeout 20s), una
        // conexión WebSocket muerta en silencio (típico de datos móviles o
        // wifi inestable — nadie manda nada mientras nadie habla) tarda
        // hasta 45s en detectarse y recién ahí arranca la reconexión del
        // cliente. Achicar esto a la mitad no cambia nada con la conexión
        // sana (solo son paquetes de ping de más), pero corta a la mitad
        // cuánto tiempo se ve "conectado" sin estarlo de verdad.
        pingInterval: 10000,
        pingTimeout: 10000,
        maxHttpBufferSize: maxPacket,
        cors: {
            origin(origin, callback) {
                callback(null, !origin || allowedOrigins.has(origin));
            },
            methods: ['GET', 'POST'],
            credentials: true,
        },
        // WebSocket no depende de CORS. Esta segunda validación impide que
        // una página de otro origen abra el repetidor directamente.
        allowRequest(request, callback) {
            callback(null, allowedOrigins.has(request.headers.origin));
        },
    });

    function speakerPayload(active) {
        return active ? { id: active.userPublicId, name: active.displayName } : null;
    }

    function releaseSpeaker(room, socketId) {
        const active = activeSpeakers.get(room);
        if (!active || active.socketId !== socketId) return;

        clearTimeout(active.timeout);
        activeSpeakers.delete(room);
        io.to(room).emit('speaker-changed', null);
    }

    function registerParticipant(room, claims) {
        const participants = roomParticipants.get(room) ?? new Map();
        const current = participants.get(claims.sub);
        participants.set(claims.sub, {
            count: (current?.count ?? 0) + 1,
            name: claims.name,
            role: claims.role,
        });
        roomParticipants.set(room, participants);

        return !current;
    }

    function unregisterParticipant(room, claims) {
        const participants = roomParticipants.get(room);
        const current = participants?.get(claims.sub);
        if (!current) return;

        if (current.count > 1) {
            participants.set(claims.sub, { ...current, count: current.count - 1 });
            return;
        }

        participants.delete(claims.sub);
        if (!participants.size) roomParticipants.delete(room);
    }

    function presencePayload(room) {
        const participants = roomParticipants.get(room) ?? new Map();

        return Array.from(participants.entries()).map(([id, participant]) => ({
            id,
            name: participant.name,
            role: participant.role,
        }));
    }

    io.use((socket, next) => {
        try {
            socket.data.claims = verifyToken(socket.handshake.auth?.token, sharedSecret);
            next();
        } catch {
            const error = new Error('No autorizado para usar la radio.');
            error.data = { code: 'RADIO_AUTH_FAILED' };
            next(error);
        }
    });

    io.on('connection', (socket) => {
        const claims = socket.data.claims;
        const room = claims.room;
        const displayName = `${claims.name} · ${claims.role}`;

        socket.join(room);

        if (registerParticipant(room, claims)) {
            // Solo se avisa a quienes ya estaban escuchando. Varias pestañas
            // del mismo usuario cuentan como una sola presencia.
            socket.to(room).emit('participant-joined', {
                id: claims.sub,
                name: claims.name,
                role: claims.role,
            });
        }
        io.to(room).emit('presence-changed', presencePayload(room));

        const current = activeSpeakers.get(room);
        if (current) socket.emit('speaker-changed', speakerPayload(current));

        // Evento mantenido para el frontend actual. La autoridad es el token.
        socket.on('join-channel', (requested) => {
            if (requested?.channel !== room) return socket.disconnect(true);
            socket.emit('speaker-changed', speakerPayload(activeSpeakers.get(room)));
        });

        socket.on('request-mic', (requestedRoom) => {
            if (requestedRoom !== room) return socket.disconnect(true);

            const active = activeSpeakers.get(room);
            if (active && active.socketId !== socket.id) {
                socket.emit('mic-denied');
                return;
            }

            if (!active) {
                const timeout = setTimeout(() => releaseSpeaker(room, socket.id), maxTalkMs);
                activeSpeakers.set(room, {
                    socketId: socket.id,
                    userPublicId: claims.sub,
                    displayName,
                    timeout,
                });
                io.to(room).emit('speaker-changed', speakerPayload(activeSpeakers.get(room)));
            }
        });

        socket.on('audio-stream', (data) => {
            const active = activeSpeakers.get(room);
            if (!active || active.socketId !== socket.id || data?.channel !== room) return;

            const audio = data.audioBlob;
            const size = Buffer.isBuffer(audio) ? audio.length : audio?.byteLength;
            if (!Number.isFinite(size) || size <= 0 || size > maxPacket) return;

            // Voz en vivo: si un receptor está congestionado se descarta el
            // paquete en vez de acumular retraso o audio en memoria.
            socket.to(room).volatile.emit('audio-receive', audio);
        });

        socket.on('release-mic', (requestedRoom) => {
            if (requestedRoom === room) releaseSpeaker(room, socket.id);
        });

        socket.on('disconnect', () => {
            releaseSpeaker(room, socket.id);
            unregisterParticipant(room, claims);
            io.to(room).emit('presence-changed', presencePayload(room));
        });
    });

    app.get('/radio/health', (_request, response) => response.json({ ok: true }));

    return {
        app,
        io,
        httpServer,
        activeSpeakers,
        roomParticipants,
        async start(port = 0, host = '127.0.0.1') {
            await new Promise((resolve, reject) => {
                httpServer.once('error', reject);
                httpServer.listen(port, host, resolve);
            });

            return httpServer.address();
        },
        async stop() {
            for (const active of activeSpeakers.values()) clearTimeout(active.timeout);
            activeSpeakers.clear();
            roomParticipants.clear();
            await new Promise((resolve) => io.close(resolve));
        },
    };
}

if (require.main === module) {
    const radio = createRadioServer();
    const port = Number(process.env.PORT || 3000);
    const host = process.env.HOST || '127.0.0.1';

    radio.start(port, host).then(() => {
        console.log(`Arka01 Radio escuchando localmente en http://${host}:${port}`);
    });

    const shutdown = () => radio.stop().finally(() => process.exit(0));
    process.on('SIGTERM', shutdown);
    process.on('SIGINT', shutdown);
}

module.exports = { createRadioServer, verifyToken };
