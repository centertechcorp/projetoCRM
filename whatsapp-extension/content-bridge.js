// Roda no mundo isolado da extensão. Recebe as mensagens postadas pelo content-main.js
// e as entrega ao service worker (background.js), que fala com o daemon.
window.addEventListener('message', (event) => {
    if (event.source !== window || event.origin !== window.location.origin) return;

    const data = event.data;
    if (!data || data.source !== 'wa-logger' || typeof data.message !== 'object' || data.message === null) return;

    chrome.runtime.sendMessage({ type: 'wa-message', message: data.message }).catch(() => {
        // service worker indisponível neste instante: o WhatsApp não é afetado
    });
});
