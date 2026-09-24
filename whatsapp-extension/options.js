const DEFAULT_URL = 'http://127.0.0.1:8765';

const urlInput = document.getElementById('url');
const tokenInput = document.getElementById('token');
const status = document.getElementById('status');

function show(text) {
    status.textContent = text;
}

function baseUrl() {
    return (urlInput.value.trim() || DEFAULT_URL).replace(/\/+$/, '');
}

chrome.storage.local.get(['url', 'token', 'queue']).then(({ url, token, queue = [] }) => {
    urlInput.value = url || DEFAULT_URL;
    tokenInput.value = token || '';
    if (queue.length > 0) show(`${queue.length} mensagem(ns) aguardando envio.`);
});

document.getElementById('save').addEventListener('click', async () => {
    await chrome.storage.local.set({ url: baseUrl(), token: tokenInput.value.trim() });
    chrome.runtime.sendMessage({ type: 'flush' });
    show('Salvo.');
});

document.getElementById('test').addEventListener('click', async () => {
    show('Testando…');

    try {
        const response = await fetch(`${baseUrl()}/health`);
        show(response.ok ? 'Daemon respondendo.' : `Daemon respondeu ${response.status}.`);
    } catch (_) {
        show('Não consegui conectar. O daemon está rodando (php artisan whatsapp:listen)?');
    }
});
