# Projeto CRM

Base Laravel + PostgreSQL para sincronizar dados do GestãoClick das lojas CENTER,
GENIUS e MIXCELL. O fuso da aplicação é `America/Sao_Paulo`.

## Rodar localmente com Docker Desktop

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate --seed
```

A aplicação fica em `http://localhost:8000` e o PostgreSQL em `localhost:5432`
(`crm` / `postgres` / `password`). Para acompanhar o servidor: `./vendor/bin/sail logs -f`.

## Tabelas

- `stores`: as três contas sincronizadas.
- `sync_runs`: execução, contadores e erro de cada sincronização.
- `gc_raw_records`: resposta crua e imutável da API; `payload_hash` é SHA-256 do payload.
- `gc_sale_statuses`: status recebido do GestãoClick e seu mapeamento interno.
- `sales`: venda tratada, recalculável a partir da camada crua. `gc_public_hash` guarda o link público do GestãoClick.
- `sale_items`: produtos de uma venda; IDs de produto e variação continuam como strings do GestãoClick.
- `sale_payments`: parcelas e formas de pagamento da venda.
- `events`: eventos internos para processamento assíncrono.
- `users`: contas locais e seu papel de acesso. Pode haver até dois owners, ocupando os slots 1 e 2.

Os valores financeiros usam `numeric(12,2)` e quantidades usam `numeric(12,3)`.
As tabelas tratadas têm chaves únicas para upsert idempotente por loja e ID GestãoClick.

### Papéis iniciais

| Papel | Usuários | Acesso |
| --- | --- | --- |
| `owner` | Caio | Tudo, incluindo criar e remover admins, ver tokens e apagar dados. |
| `admin` | Matheus, Rodrigo | Sincronização, mapeamento de situações, usuários abaixo de admin e todas as lojas. |
| `manager` | João Pedro | CRM, relatórios e lojas; pode reatribuir leads. |
| `seller` | Vendedoras | Somente leads próprios e sua loja. |

## Daemon do WhatsApp

`php artisan whatsapp:listen` sobe um servidor HTTP local (padrão `127.0.0.1:8765`) que recebe as
mensagens enviadas pela extensão do Chrome em `whatsapp-extension/` e grava cada uma, sem nunca
reescrever, no arquivo do contato: `storage/app/whatsapp/<numero>.txt` (grupos:
`grupo_<id>.txt`). Cada linha é `[2026-09-23 21:30:12] in|out | Nome: texto`, com quebras de
linha escritas como `\n` e mídia como `[imagem]`, `[áudio]` etc. Os ids já gravados ficam em
`storage/app/whatsapp/.seen/` para que reenvios não dupliquem linhas.

Configure em `.env`: `WHATSAPP_TOKEN` (obrigatório; o mesmo token vai nas opções da extensão),
`WHATSAPP_HOST` e `WHATSAPP_PORT`. Rode no host (`php artisan whatsapp:listen`), não no Sail; dentro
do Sail é preciso `--host=0.0.0.0` e publicar a porta no `docker-compose.yml`. A instalação da
extensão está em `whatsapp-extension/README.md`.

### Mensagens do WhatsApp no banco

Com `WHATSAPP_DATABASE=true` (ou `php artisan whatsapp:listen --database`) o daemon grava também no
banco, e os `.txt` viram cópia de segurança (uma subpasta por conta). Rodando no host, use
`DB_HOST=127.0.0.1`: o nome `pgsql` só existe dentro do Sail.

Cada número de WhatsApp é uma **conta** (`whatsapp_accounts`), ligada a uma loja, com o próprio token:

```bash
php artisan whatsapp:account CENTER "WhatsApp CENTER" --phone=5534999998888   # mostra o token uma vez
```

Nesse modo o token da extensão é o da conta (o `WHATSAPP_TOKEN` deixa de valer); use um perfil do
Chrome por número. Tabelas: `whatsapp_accounts`, `whatsapp_chats` (conversa ou grupo por conta),
`whatsapp_messages` (única por conta e id da mensagem; guarda o payload cru) e `whatsapp_media`
(metadados de foto, áudio etc. e a transcrição; o arquivo fica no disco, não no banco).

A conta guarda em `provider_account_ref` a referência do provedor (instance do Evolution, session do WAHA
ou `phone_number_id` da Cloud API) para achar a conta de um webhook. Mídia sem arquivo baixado (a Cloud
API só manda um id) fica com `download_status = pending` e evento `whatsapp.media.pending`. Mensagens
guardam `quoted_external_id` (resposta ou reação) e `sent_via` (`human`, `agent` ou `api`).

Toda escrita passa por `App\Services\Whatsapp\MessageRecorder`. Para usar Evolution, WAHA ou a API
oficial no futuro, um webhook converte o payload do provedor em `IncomingMessage` (com
`IncomingMedia` quando houver mídia) e chama `record()`: as tabelas não mudam. A captura de
foto e áudio e a transcrição ainda não existem; a extensão só envia texto e legenda.

### Webhook da Meta WhatsApp Cloud API

`GET/POST /api/whatsapp/meta` recebe as mensagens da Cloud API e chama o mesmo
`MessageRecorder`, sem mudar tabela nenhuma. `App\Services\Whatsapp\MetaCloudApiAdapter`
converte o payload da Meta (`entry[].changes[].value`) em `IncomingMessage`/`IncomingMedia`; a
Cloud API não tem grupos, e mídia chega só com um id (o download em si fica para depois, com
`download_status = pending`).

Configure em `.env`: `META_WHATSAPP_VERIFY_TOKEN` (valor que você escolhe, usado na checagem do
webhook) e `META_WHATSAPP_APP_SECRET` (do app, valida a assinatura `X-Hub-Signature-256` de cada
requisição). Crie a conta com o `phone_number_id` do painel da Meta:

```bash
php artisan whatsapp:account CENTER "WhatsApp CENTER (Meta)" --provider=cloud_api --ref=<phone_number_id>
```

**Checklist para testar com o número de teste da Meta (não precisa da empresa verificada):**

1. Em [developers.facebook.com/apps](https://developers.facebook.com/apps), **Criar app** → "Conectar
   com clientes pelo WhatsApp" → escolher/criar um portfólio de negócio.
2. Na tela do produto WhatsApp, **Start using the API** → **Generate access token**.
3. Anote o **Phone number ID** que aparece em "From" e o **token de acesso** temporário.
4. Em **To**, adicione um número seu como destinatário de teste e confirme o código recebido.
5. No WSL, exponha o app publicamente: `ngrok http 8000` (ajuste a porta se o Sail usar outra) e
   copie a URL `https://...ngrok-free.app`.
6. No painel do app, em **WhatsApp → Configuration → Webhook**, informe
   `https://...ngrok-free.app/api/whatsapp/meta` e o mesmo valor de `META_WHATSAPP_VERIFY_TOKEN`,
   e clique em **Verify and save** (a Meta faz a checagem GET nesse momento). Inscreva-se no campo
   `messages`.
7. Mande uma mensagem do número de teste para o número em "To" e confira se apareceu em
   `whatsapp_messages`.

Um túnel do `ngrok` grátis muda de endereço a cada reinício, então serve só para teste; produção
precisa de domínio e servidor fixos, com o webhook reconfigurado para essa URL definitiva.
