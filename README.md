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

## CRM de leads (a partir do WhatsApp)

Transforma conversas de WhatsApp paradas em **leads** para reabordar, e mantém quem já comprou
para o pós-venda. Tabelas: `customers` (um por telefone), `leads` (um aberto por cliente e loja;
histórico preservado quando fecha) e `lead_followups` (a sugestão de mensagem, sempre pendente de
aprovação de uma pessoa — nada é enviado pelo sistema).

`App\Services\Whatsapp\StalledConversationDetector` cria/atualiza leads a partir de
`whatsapp_chats`/`whatsapp_messages`: conversa individual, parada há `WHATSAPP_FOLLOWUP_MIN_IDLE_HOURS`
(padrão 24h) e no máximo `WHATSAPP_FOLLOWUP_MAX_AGE_DAYS` (padrão 30 dias), com pelo menos uma
mensagem do cliente. `customer_unanswered` = o cliente falou por último; `customer_silent` =
respondemos e ele sumiu. Não repete sugestão em aberto nem antes do `WHATSAPP_FOLLOWUP_COOLDOWN_DAYS`
(padrão 14 dias). Idempotente: rodar de novo sem mensagem nova não duplica nada.

Dois filtros evitam sugestão sem necessidade: se a última mensagem do cliente for só uma despedida
("obrigado", "valeu", "👍" — lista em `whatsapp.followup.closing_phrases`), não sugere reabordagem,
mas o lead continua registrado; e depois de `WHATSAPP_FOLLOWUP_MAX_ATTEMPTS` (padrão 3) sugestões
para o mesmo lead, o detector para de insistir (fica só o histórico, visível no painel).

```bash
php artisan whatsapp:leads:detect [--store=CENTER]     # procura conversas paradas
php artisan whatsapp:leads:list [--status=open|won|lost|all]
php artisan whatsapp:leads:close <id> won|lost [--reason=]
php artisan whatsapp:leads:archive                     # arquiva quem "comprou" após a retenção (nunca apaga)
php artisan whatsapp:followups:review <id> approve|dismiss --user=<id>
```

Quem comprou (`status=won`) nunca é excluído: fica `WHATSAPP_FOLLOWUP_RETENTION_DAYS` (padrão 30
dias) disponível para pós-venda antes de `whatsapp:leads:archive` marcar como arquivado.

`whatsapp:leads:detect` roda sozinho, uma vez por dia às 18h (`routes/console.php`), pelo serviço
`scheduler` do `docker-compose.yml` — um contêiner que fica chamando `php artisan schedule:run`
a cada minuto, para não depender de cron no WSL (que não persiste entre reinícios). Só funciona
enquanto o Docker estiver de pé (`sail up -d`); `docker logs centercorp-scheduler-1` mostra o que
ele andou fazendo.

### Reconectar quem desistiu (`status=lost`)

`whatsapp:leads:detect` também roda `App\Services\Leads\LostLeadReconnector`: quem foi marcado como
perdido ganha uma nova sugestão de reabordagem (motivo `lost_recovery`) depois de
`WHATSAPP_FOLLOWUP_LOST_RECONNECT_AFTER_DAYS` (padrão 5 dias) — mesmo que o cliente tenha dito
explicitamente que não queria nada; ninguém fica de fora. Vale o mesmo teto de tentativas e
cooldown do resto (`App\Services\Leads\FollowupGate`, usado pelos dois detectores).

A IA só escreve o rascunho dessa primeira mensagem (quando a Fase 2 existir); uma pessoa sempre
aprova e manda pelo painel — sem exceção. Ao **aprovar**, `LeadDecisionService` já reabre o lead
(`status=open`, limpa `lost_at`/`lost_reason`): ele sai da aba "Desistiu" e aparece em "Reconectar",
e dali em diante quem conversa com o cliente é o funcionário, não o sistema.

### Painel (`/painel`)

Login simples (guard `web`, tabela `users`; sem registro público). Defina a senha de alguém já
cadastrado (a seed inicial só tem nome, sem e-mail):

```bash
php artisan user:password caio@empresa.com --name="Caio"   # pede a senha, nunca a imprime
php artisan user:decide Caio on                             # libera aprovar/descartar/decidir no painel
```

`seller` só vê a loja em `users.store_id`; `manager`/`admin`/`owner` veem todas. Já **decidir**
(aprovar, descartar, marcar como vendido/perdido) é por pessoa, não por papel — só quem tem
`users.can_decide = true` (ligado com `user:decide`) vê os botões; todo mundo começa desligado.
O painel lista os leads (abas Em andamento / Reconectar / Pós-venda / Desistiu / Todos) com a
sugestão de reabordagem e os botões **Aprovar**, **Descartar**, **Comprou**, **Perdeu** e **Abrir no
WhatsApp** (`wa.me` com o texto pronto quando existir, só depois de aprovado — o envio em si
continua manual, feito pela pessoa).

Dados fictícios para ver o painel funcionando antes de haver conversa real:
`php artisan db:seed --class=WhatsappDemoSeeder` (nunca em produção; não roda pelo seeder padrão).

### Importar um export manual (sem webhook em tempo real)

Enquanto não há um conector ao vivo (extensão, Meta, WAHA/Evolution) para um número, dá para
importar um arquivo que alguém exportou manualmente (ex.: um dump do bot de outro desenvolvedor):

```bash
php artisan whatsapp:import:file caminho/export.json --account=<id ou label exato da conta>
php artisan whatsapp:leads:detect   # depois, gera os leads normalmente a partir do que foi importado
```

Aceita `.json` (lista de objetos) ou `.csv` (com cabeçalho), nas mesmas colunas que
`App\Services\Whatsapp\IncomingMessage::fromArray` já usa: `id, chat, from_me, sender_name, body,
type, timestamp`, e opcionalmente `sender_jid, quoted_external_id, sent_via`. No CSV, `from_me`
aceita `1/0` ou `true/false`, e `timestamp` pode vir como texto (segundos desde 1970). Linhas
inválidas são puladas e contadas, sem interromper o restante do arquivo; a saída do comando mostra
só a contagem, nunca telefone ou texto de cliente no terminal.

### Assets (Tailwind/Vite)

O painel usa Tailwind 4 via Vite. Compile antes de abrir no navegador: `npm install && npm run build`
(ou `npm run dev` durante o desenvolvimento). Nos testes automatizados isso não é necessário —
`Tests\TestCase` já chama `withoutVite()`.

### Sessão, cache e fila

`SESSION_DRIVER`, `CACHE_STORE` e `QUEUE_CONNECTION` usam `database` no `.env`, e por isso as tabelas
`sessions`, `cache`/`cache_locks` e `jobs` (migrations `2026_09_27_182447` a `182449`) fazem parte do
`php artisan migrate`. Sem elas, login e qualquer coisa que use `RateLimiter`/cache derruba com
`relation "sessions" does not exist`.
