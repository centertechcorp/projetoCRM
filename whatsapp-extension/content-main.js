// Roda no mundo MAIN da página, com acesso ao módulo interno do WhatsApp Web.
// Escuta a coleção de mensagens (Msg) e repassa cada mensagem nova ao content-bridge.js
// via window.postMessage. Não tem acesso a chrome.* (por isso existe o bridge).
(() => {
    'use strict';

    const TAG = '[wa-logger]';
    const SOURCE = 'wa-logger';
    const STARTED_AT = Math.floor(Date.now() / 1000);
    // Ao abrir a aba o WhatsApp carrega o histórico e dispara "add" para mensagens antigas.
    // Só interessam as mensagens de agora em diante (com uma folga para diferença de relógio).
    const GRACE_SECONDS = 30;
    const MAX_REMEMBERED_IDS = 5000;

    // Mensagens de sistema: não são conversa. "ciphertext" ainda não foi decifrada; ela é
    // reemitida quando o tipo muda (evento change:type) depois de decifrada.
    const SKIP_TYPES = new Set([
        'ciphertext',
        'e2e_notification',
        'notification_template',
        'gp2',
        'protocol',
        'call_log',
        'broadcast_notification',
        'oversized',
        'debug',
    ]);

    const emitted = new Set();

    // Versões antigas expõem `_serialized`; as atuais entregam um objeto cujo texto (toString) é o id.
    const serialized = (wid) => {
        if (wid === null || wid === undefined) return null;
        if (typeof wid === 'string') return wid;
        if (typeof wid._serialized === 'string' && wid._serialized) return wid._serialized;

        const text = String(wid);
        return text && text !== '[object Object]' ? text : null;
    };

    function findCollections() {
        const req = window.require;
        if (typeof req !== 'function') return null;

        try {
            const direct = req('WAWebCollections');
            if (direct && direct.Msg && direct.Contact) return direct;
        } catch (_) {
            // módulo ainda não carregado ou renomeado: tenta a busca abaixo
        }

        try {
            const modules = req('__debug')?.modulesMap;
            for (const name of Object.keys(modules || {})) {
                if (!/Collections/.test(name)) continue;
                try {
                    const candidate = req(name);
                    if (candidate?.Msg?.on && candidate?.Contact) return candidate;
                } catch (_) {
                    // ignora módulos que não inicializam
                }
            }
        } catch (_) {
            // sem __debug
        }

        return null;
    }

    // Contas com privacidade de número usam JIDs "@lid". Quando o contato expõe o telefone, usa ele.
    function chatJid(msg, collections) {
        const remote = msg.id?.remote;
        const jid = serialized(remote);

        if (jid && jid.endsWith('@lid')) {
            try {
                const phone = serialized(collections.Contact.get(remote)?.phoneNumber);
                if (phone) return phone;
            } catch (_) {
                // mantém o @lid
            }
        }

        return jid;
    }

    function senderName(msg, collections) {
        if (msg.id?.fromMe) return '';

        let contact = null;
        try {
            contact = collections.Contact.get(msg.author || msg.from);
        } catch (_) {
            // sem contato: usa o nome que veio na mensagem
        }

        return contact?.name || contact?.pushname || msg.notifyName || contact?.shortName || '';
    }

    // Em mensagens de mídia, "body" é a miniatura em base64: só a legenda interessa.
    function textOf(msg) {
        if (msg.type === 'chat') return msg.body || '';
        return msg.caption || msg.pollName || '';
    }

    function emit(msg, collections) {
        try {
            const id = serialized(msg?.id);
            if (!id || emitted.has(id) || SKIP_TYPES.has(msg.type)) return;

            const timestamp = Number(msg.t);
            if (!Number.isFinite(timestamp) || timestamp < STARTED_AT - GRACE_SECONDS) return;

            const chat = chatJid(msg, collections);
            if (!chat) return;

            emitted.add(id);
            if (emitted.size > MAX_REMEMBERED_IDS) emitted.delete(emitted.values().next().value);

            window.postMessage(
                {
                    source: SOURCE,
                    message: {
                        id,
                        chat,
                        from_me: Boolean(msg.id.fromMe),
                        sender_name: String(senderName(msg, collections) || ''),
                        sender_jid: msg.id.fromMe ? null : serialized(msg.author) || serialized(msg.id.participant),
                        body: String(textOf(msg)),
                        type: String(msg.type || 'chat'),
                        timestamp: Math.floor(timestamp),
                    },
                },
                window.location.origin,
            );
        } catch (error) {
            console.error(TAG, 'falha ao processar mensagem', error);
        }
    }

    function attach(collections) {
        collections.Msg.on('add', (msg) => emit(msg, collections));
        collections.Msg.on('change:type', (msg) => emit(msg, collections));
        console.info(TAG, 'ativo: escutando mensagens do WhatsApp Web');
    }

    // O módulo interno só existe depois que a página carrega e a conta está logada.
    let attempts = 0;
    const timer = setInterval(() => {
        const collections = findCollections();

        if (collections) {
            clearInterval(timer);
            attach(collections);
            return;
        }

        attempts += 1;
        if (attempts % 30 === 0) {
            console.warn(TAG, 'ainda não encontrei o store interno do WhatsApp (logado? versão nova?)');
        }
    }, 2000);
})();
