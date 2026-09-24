# WhatsApp Logger (extensão Chrome)

Captura cada mensagem do WhatsApp Web, recebida ou enviada, em qualquer conversa, e envia ao
daemon local `php artisan whatsapp:listen`, que grava um arquivo de texto por contato.

## Instalar

1. Defina `WHATSAPP_TOKEN` no `.env` do projeto e inicie o daemon: `php artisan whatsapp:listen`.
2. No Chrome, abra `chrome://extensions`, ative o **Modo do desenvolvedor**, clique em
   **Carregar sem compactação** e escolha esta pasta (`whatsapp-extension/`).
3. Clique no ícone da extensão (abre as opções), informe o mesmo token e clique em **Salvar**.
   **Testar conexão** confirma que o daemon está respondendo.
4. Abra (ou recarregue) `https://web.whatsapp.com`. No console da página (F12) deve aparecer
   `[wa-logger] ativo: escutando mensagens do WhatsApp Web`.

## Como funciona

- `content-main.js` (mundo MAIN da página) escuta a coleção interna de mensagens do WhatsApp Web.
- `content-bridge.js` repassa cada mensagem ao service worker.
- `background.js` envia por POST ao daemon. Se o daemon estiver fora do ar, as mensagens ficam
  numa fila e são reenviadas a cada minuto (o daemon ignora ids repetidos). O selo do ícone
  mostra `!` (token ausente ou errado) ou o tamanho da fila pendente.

## Limitações

- Depende de módulos internos e não documentados do WhatsApp Web. Se uma atualização os mudar,
  o console mostra `ainda não encontrei o store interno` e `content-main.js` precisa de ajuste.
- Só registra mensagens de agora em diante: o histórico carregado ao abrir a aba é ignorado, e
  mensagens sincronizadas de quando o navegador estava fechado também.
- Só funciona com a aba do WhatsApp Web aberta.
