---
name: produto-orcamento-vazio
description: Explica por que a coluna Produto/Orçamento do painel mostra "—" ou um termo genérico, e como adicionar uma palavra-chave nova e reprocessar os leads existentes. Use quando o usuário perguntar por que o produto ou valor de um lead está vazio/errado, ou pedir pra adicionar uma palavra que não está sendo reconhecida.
---

# Por que Produto/Orçamento fica "—" (e como corrigir)

## Como o preenchimento funciona

Serviço: `app/Services/Leads/LeadInfoExtractor.php`, chamado dentro do
`StalledConversationDetector::processChat()` quando a conversa fica idle e vira
(ou atualiza) um Lead.

- **`product_interest`** — procura, nas últimas 10 mensagens do CLIENTE (mais recente
  primeiro), a primeira palavra que bater em `config('whatsapp.group_topics.keywords')`.
  Devolve a palavra-chave em si (ex. "iphone"), não o produto exato com modelo — é uma
  categoria genérica por regra, não extração real via IA. Isso é limitação conhecida e
  aceita, não bug.
- **`quoted_amount`** — procura `R$` nas mensagens ENVIADAS (do vendedor, não do cliente),
  regex `/r\$\s*([\d.,]+)/i`, pega o valor mais recente.
- Um valor novo só SOBRESCREVE o campo existente se vier algo — não limpa um
  `product_interest`/`quoted_amount` já preenchido se a mensagem mais recente não tiver
  sinal nenhum (não queremos perder um orçamento já dado).

## Por que aparece "—"

1. Nenhuma das últimas 10 mensagens do cliente bateu com nenhuma palavra da lista — termo
   genérico demais, gíria, erro de digitação, ou simplesmente um termo que não está na
   lista ainda.
2. A menção ao produto ficou fora da janela das últimas 10 mensagens (conversa longa, o
   assunto "envelheceu" pra fora do limite).
3. `quoted_amount` fica vazio se o vendedor nunca escreveu "R$" explicitamente (passou o
   valor de outro jeito, por áudio, etc. — o extrator só lê texto).

## Ordem da lista de keywords importa

Em `config/whatsapp.php`, `group_topics.keywords`: o extrator pega a PRIMEIRA palavra que
bater. Termos de marca/modelo específicos (ex. "samsung", "iphone") têm que vir ANTES de
termos genéricos (ex. "celular", "smartphone") — senão "esse celular samsung" resolve pra
"celular" em vez de "samsung". Os genéricos (`celular`, `smartphone`, `eletronico`/
`eletrônico`) já estão no fim de propósito — manter essa ordem ao adicionar termo novo.

## Como adicionar um termo novo e reprocessar

1. Edita `config/whatsapp.php`, array `group_topics.keywords` — adiciona o termo
   (específico primeiro, genérico por último, seguindo o padrão acima).
2. `php artisan config:clear` (dentro do container).
3. Reprocessa os leads existentes:
   ```
   docker exec centercorp-laravel.test-1 php artisan whatsapp:leads:detect
   ```
   Isso só preenche campos vazios ou atualiza se achar sinal novo — não precisa ter medo de
   rodar de novo, é seguro repetir.
4. Confirma a contagem antes/depois via tinker:
   ```
   docker exec centercorp-laravel.test-1 php artisan tinker --execute="
   echo \App\Models\Lead::whereNotNull('product_interest')->count();
   "
   ```
