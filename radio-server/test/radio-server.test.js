'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { afterEach, beforeEach, test } = require('node:test');
const { io: createClient } = require('socket.io-client');
const { createRadioServer } = require('../server');

const SECRET = 'arka01-radio-test-secret-'.repeat(4);
const ORIGIN = 'http://127.0.0.1:8000';
const ROOM = `arka01-${'a'.repeat(64)}`;

let radio;
let url;
let clients;

function base64Url(value) {
    return Buffer.from(value).toString('base64url');
}

function tokenFor({ id, name, role = 'cliente', room = ROOM, expiresIn = 300 }) {
    const now = Math.floor(Date.now() / 1000);
    const payload = base64Url(JSON.stringify({ sub: id, name, role, room, iat: now, exp: now + expiresIn }));
    const signature = crypto.createHmac('sha256', SECRET).update(payload).digest('base64url');
    return `${payload}.${signature}`;
}

function once(socket, event) {
    return new Promise((resolve) => socket.once(event, resolve));
}

async function connect(token) {
    const client = createClient(url, {
        path: '/socket.io',
        transports: ['websocket'],
        auth: token ? { token } : {},
        extraHeaders: { Origin: ORIGIN },
        forceNew: true,
        reconnection: false,
    });
    clients.push(client);
    await once(client, 'connect');
    return client;
}

beforeEach(async () => {
    clients = [];
    radio = createRadioServer({
        sharedSecret: SECRET,
        allowedOrigins: [ORIGIN],
        socketPath: '/socket.io',
        maxTalkMs: 1000,
    });
    const address = await radio.start();
    url = `http://127.0.0.1:${address.port}`;
});

afterEach(async () => {
    clients.forEach((client) => client.disconnect());
    await radio.stop();
});

test('rechaza conexiones sin token firmado', async () => {
    const client = createClient(url, {
        path: '/socket.io',
        transports: ['websocket'],
        extraHeaders: { Origin: ORIGIN },
        reconnection: false,
    });
    clients.push(client);

    const error = await once(client, 'connect_error');
    assert.equal(error.data.code, 'RADIO_AUTH_FAILED');
});

test('concede un solo turno y lo libera para la siguiente persona', async () => {
    const laura = await connect(tokenFor({ id: 'public-laura', name: 'Laura' }));
    const gregorio = await connect(tokenFor({ id: 'public-gregorio', name: 'Gregorio', role: 'conductor' }));

    const lauraSpeaking = once(laura, 'speaker-changed');
    laura.emit('request-mic', ROOM);
    assert.deepEqual(await lauraSpeaking, { id: 'public-laura', name: 'Laura · cliente' });

    const denied = once(gregorio, 'mic-denied');
    gregorio.emit('request-mic', ROOM);
    await denied;

    const channelFree = once(gregorio, 'speaker-changed');
    laura.emit('release-mic', ROOM);
    assert.equal(await channelFree, null);

    const gregorioSpeaking = once(gregorio, 'speaker-changed');
    gregorio.emit('request-mic', ROOM);
    assert.deepEqual(await gregorioSpeaking, { id: 'public-gregorio', name: 'Gregorio · conductor' });
});

test('avisa al otro participante cuando alguien se conecta a la radio de carrera', async () => {
    const laura = await connect(tokenFor({ id: 'public-laura', name: 'Laura' }));
    const joined = once(laura, 'participant-joined');

    await connect(tokenFor({ id: 'public-gregorio', name: 'Gregorio', role: 'conductor' }));

    assert.deepEqual(await joined, {
        id: 'public-gregorio',
        name: 'Gregorio',
        role: 'conductor',
    });

    // Abrir una segunda pestaña no debe generar otra notificación con el
    // mismo nombre mientras su primera conexión siga activa.
    let duplicated = false;
    laura.once('participant-joined', () => { duplicated = true; });
    await connect(tokenFor({ id: 'public-gregorio', name: 'Gregorio', role: 'conductor' }));
    await new Promise((resolve) => setTimeout(resolve, 40));
    assert.equal(duplicated, false);
});

test('mantiene una lista de personas conectadas sin duplicar pestañas', async () => {
    const laura = await connect(tokenFor({ id: 'laura', name: 'Laura' }));

    const twoConnected = once(laura, 'presence-changed');
    await connect(tokenFor({ id: 'gregorio', name: 'Gregorio', role: 'conductor' }));

    const participants = await twoConnected;
    assert.equal(participants.length, 2);
    assert.deepEqual(participants.map((participant) => participant.name).sort(), ['Gregorio', 'Laura']);

    const duplicate = await connect(tokenFor({ id: 'gregorio', name: 'Gregorio', role: 'conductor' }));

    const snapshot = await new Promise((resolve) => {
        laura.once('presence-changed', resolve);
        duplicate.disconnect();
    });
    assert.equal(snapshot.length, 2);
});

test('solo retransmite audio del socket que posee el turno', async () => {
    const speaker = await connect(tokenFor({ id: 'speaker', name: 'Unidad 1', role: 'conductor' }));
    const listener = await connect(tokenFor({ id: 'listener', name: 'Cliente' }));
    const impostor = await connect(tokenFor({ id: 'impostor', name: 'Unidad 2', role: 'conductor' }));

    const granted = once(speaker, 'speaker-changed');
    speaker.emit('request-mic', ROOM);
    await granted;

    let impostorWasRelayed = false;
    listener.once('audio-receive', (audio) => {
        if (Buffer.from(audio).toString() === 'impostor') impostorWasRelayed = true;
    });
    impostor.emit('audio-stream', { channel: ROOM, audioBlob: Buffer.from('impostor') });
    await new Promise((resolve) => setTimeout(resolve, 40));
    assert.equal(impostorWasRelayed, false);

    const received = once(listener, 'audio-receive');
    speaker.emit('audio-stream', { channel: ROOM, audioBlob: Buffer.from('audio-real') });
    assert.equal(Buffer.from(await received).toString(), 'audio-real');
});
