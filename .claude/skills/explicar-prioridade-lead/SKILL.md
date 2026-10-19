---
name: explicar-prioridade-lead
description: Explica por que um lead ou conversa recebeu determinada prioridade/score (Alta/Média/Baixa, badge com número) no painel ou no Relatório do dia. Use quando o usuário perguntar por que um contato específico tem uma pontuação ou prioridade que parece estranha, alta ou baixa demais, ou pedir pra investigar um badge de prioridade.
---

# Como o score de prioridade é calculado

Serviço: `app/Services/Leads/LeadPriorityScorer.php`. Olha só as últimas 10 mensagens
RECEBIDAS do cliente naquele chat (`MAX_MESSAGES_CONSIDERED`), sem IA — só regra de texto.
Pontuação é soma destes itens, capada em 100:

| Critério | Pontos | Detalhe |
|---|---|---|
| `quoted_amount` já preenchido no Lead | +30 | Só existe quando já tem Lead (não conta no Relatório antes de virar lead) |
| Pergunta/menção de fechamento | +25 | Bate qualquer palavra de `CLOSING_KEYWORDS`: parcela, parcelamento, boleto, cartão, desconto, à vista, **entrega**, garantia, disponível, troca, quanto, preço, valor |
| Menciona produto | +15 | Bate `config('whatsapp.group_topics.keywords')`, ou já tem `product_interest` preenchido |
| Volume de mensagens | até +15 | `count * 3`, capado em 15 (5+ mensagens já bate o teto) |
| Quantidade de `?` no texto | até +15 | `count('?') * 5`, capado em 15 |

Label: `>= 50` Alta, `>= 25` Média, `< 25` Baixa.

## Armadilha já vista na prática (caso real: "Multishine Casa Da Limpeza")

As `CLOSING_KEYWORDS` incluem palavras genéricas demais pro contexto de venda — **"entrega"**
é a mais perigosa: qualquer fornecedor com bot de atendimento automático que menciona prazo
de entrega bate esse critério, mesmo sem ter NENHUMA intenção de compra real. Combinado com
o bônus de volume de mensagens, uma conversa de fornecedor pode facilmente chegar a 40
("Média") só de ruído.

## Roteiro de investigação

1. Acha o chat (`WhatsappChat`) e puxa as últimas 10 mensagens recebidas.
2. Roda o texto contra as duas listas (`CLOSING_KEYWORDS` acima, e
   `config('whatsapp.group_topics.keywords')`) pra achar qual bateu.
3. Soma manualmente os pontos pra confirmar o número exibido.
4. Se o resultado for falso-positivo tipo o caso do fornecedor: a solução não é mudar a
   fórmula pra esse caso específico — é usar o botão "Ignorar" (painel ou Relatório do dia,
   ver [[painel-vs-relatorio]]) pra marcar o chat como `ignored=true` e sumir pra sempre dos
   dois lugares, sem precisar ter virado Lead antes.

## Nuance: duas versões do mesmo cálculo

- `score(Lead $lead)` — usado no painel, já tem Lead, inclui bônus de `quoted_amount`.
- `scoreMessages(Collection $customerMessages)` — usado no Relatório do dia
  (`PainelReportController`), ranking "Gerar leads do dia", SEM o bônus de `quoted_amount`
  (não existe Lead ainda nesse ponto).
