---
name: painel-vs-relatorio
description: Explica a diferença entre o Painel principal e o Relatório do dia quando uma conversa aparece em um e não no outro, quando a busca não encontra um contato, ou quando não há botão de ação esperado. Use sempre que o usuário estranhar que um contato "não existe", não aparece na busca do painel, ou perguntar por que o Relatório mostra algo que o painel não mostra.
---

# Painel e Relatório do dia são duas fontes de dados diferentes

## Painel (`PainelController`, rota `painel.index`)

Lê a partir de **`Lead`** (com `Customer`, `Store`, `WhatsappChat`, `followups`). Só mostra
conversas que já passaram pelo `StalledConversationDetector` e viraram um registro de Lead
(depois de `WHATSAPP_FOLLOWUP_MIN_IDLE_HOURS`, hoje 12h, de inatividade — ver
`config('whatsapp.min_idle_hours')`).

**Consequência prática:** a busca do painel (`painel.parts.search` / busca ao vivo) só
encontra Lead/Customer. Uma conversa que nunca ficou idle o suficiente pra virar Lead, ou
que o usuário quer tratar antes disso, **não aparece em busca nenhuma do painel** — não é
bug, é porque o registro não existe ali.

## Relatório do dia (`PainelReportController`, rota `painel.report`)

Lê direto de **`WhatsappMessage`**/`WhatsappChat`, filtrando por dia (ou busca por nome/
telefone independente do dia). Não depende de ter virado Lead. Desde a mudança recente,
também faz um lookup de `Lead::whereIn('whatsapp_chat_id', ...)` só pra decidir **quais
botões de ação mostrar**:
- Já existe Lead pra aquele chat → mostra o conjunto completo
  (`painel.partials.lead-actions`: Aprovar, Copiar link, Comprou, Perdeu, Deletar...).
- Ainda não existe Lead → mostra só "Ignorar" (marca `WhatsappChat.ignored = true` direto,
  via `PainelReportController::ignoreChat()`, sem precisar de Lead).

O badge `#{{ $i+1 }} 🟡 Média · 40` no Relatório é **posição de ranking** (ordenação por
score daquela execução), não um ID de Lead — não confundir com o número de Lead do painel.

## Caso real que motivou isso (histórico)

Contato "Multishine" aparecia no Relatório com badge de prioridade, mas não existia em
nenhuma busca do painel — porque nunca tinha virado Lead (era um fornecedor, não um
cliente). A confusão foi achar que devia existir um Lead pra deletar; a resposta certa foi
usar o "Ignorar" do próprio Relatório.

## Como diagnosticar rápido

1. Pergunta: "esse contato tem o botão completo de ações ou só 'Ignorar'?" — já responde se
   tem Lead ou não, sem precisar consultar banco.
2. Se precisar confirmar no banco:
   ```
   docker exec centercorp-laravel.test-1 php artisan tinker --execute="
   echo \App\Models\Lead::where('whatsapp_chat_id', <id>)->exists() ? 'tem lead' : 'sem lead';
   "
   ```
3. "Ignorar" (sem Lead) e "Deletar" (com Lead) fazem a MESMA coisa no fundo —
   `WhatsappChat.ignored = true` — só muda se passa por
   `LeadDecisionService::deletePermanently()` (que também apaga o Lead) ou direto por
   `PainelReportController::ignoreChat()` (quando não tem Lead pra apagar).
