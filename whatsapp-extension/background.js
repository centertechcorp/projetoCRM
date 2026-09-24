// Service worker: recebe as mensagens do content-bridge.js e as envia por POST ao daemon.
// Mensagens que não puderam ser entregues ficam numa fila em chrome.storage.local e são
// reenviadas a cada minuto. O daemon deduplica por id, então reenviar é seguro.

const DEFAULTS = { url: 'http://127.0.0.1:8765', token: '' };
const MAX_QUEUE = 5000;
const ALARM = 'wa-logger-flush';

let chain = Promise.resolve();
let flushing = false;

// Leitura-modificação-escrita da fila precisa ser uma de cada vez.
function serial(task) {
    chain = chain.catch(() => {}).then(task);
    return chain;
}

async function settings() {
    const stored = await chrome.storage.local.get(['url', 'token']);
    return {
        url: (stored.url || DEFAULTS.url).replace(/\/+$/, ''),
        token: stored.token || DEFAULTS.token,
    };
}

function badge(text) {
    chrome.action.setBadgeText({ text });
}

async function enqueue(message) {
    await serial(async () => {
        const { queue = [] } = await chrome.storage.local.get('queue');
        queue.push(message);
        await chrome.storage.local.set({ queue: queue.slice(-MAX_QUEUE) });
    });

    flush();
}

// 'ok' | 'drop' (o daemon rejeitou a mensagem: reenviar não adianta) | 'auth' | 'retry'
async function send(config, message) {
    try {
        const response = await fetch(`${config.url}/messages`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Token': config.token },
            body: JSON.stringify(message),
        });

        if (response.ok) return 'ok';
        if (response.status === 401) return 'auth';
        return response.status >= 400 && response.status < 500 ? 'drop' : 'retry';
    } catch (_) {
        return 'retry';
    }
}

async function flush() {
    if (flushing) return;
    flushing = true;

    try {
        const config = await settings();

        while (true) {
            const { queue = [] } = await chrome.storage.local.get('queue');

            if (queue.length === 0) {
                badge('');
                return;
            }

            if (!config.token) {
                badge('!');
                return;
            }

            const outcome = await send(config, queue[0]);

            if (outcome === 'auth' || outcome === 'retry') {
                badge(outcome === 'auth' ? '!' : String(Math.min(queue.length, 999)));
                return; // o alarme periódico tenta de novo
            }

            await serial(async () => {
                const current = (await chrome.storage.local.get('queue')).queue || [];
                current.shift();
                await chrome.storage.local.set({ queue: current });
            });
        }
    } finally {
        flushing = false;
    }
}

async function ensureAlarm() {
    if (!(await chrome.alarms.get(ALARM))) {
        chrome.alarms.create(ALARM, { periodInMinutes: 1 });
    }
}

chrome.runtime.onMessage.addListener((request) => {
    if (request?.type === 'wa-message') enqueue(request.message);
    if (request?.type === 'flush') flush();
});

chrome.alarms.onAlarm.addListener((alarm) => {
    if (alarm.name === ALARM) flush();
});

chrome.action.onClicked.addListener(() => chrome.runtime.openOptionsPage());
chrome.runtime.onInstalled.addListener(ensureAlarm);
chrome.runtime.onStartup.addListener(ensureAlarm);

ensureAlarm();
flush();
