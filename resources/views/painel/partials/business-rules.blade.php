@php
    $f = config('whatsapp.followup');
@endphp

<details class="mb-6 rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <summary class="cursor-pointer select-none rounded-xl px-4 py-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800">
        Como funciona / Regras do sistema
    </summary>

    <div class="space-y-5 border-t border-slate-200 px-4 py-4 text-sm text-slate-700 dark:border-slate-800 dark:text-slate-300">
        <div>
            <h3 class="mb-1 font-semibold text-slate-900 dark:text-slate-100">Quando uma conversa vira lead sozinha</h3>
            <ul class="list-inside list-disc space-y-1">
                <li>Só conta conversa <strong>individual</strong> — grupo nunca vira lead.</li>
                <li>A última mensagem (de qualquer lado) precisa estar entre <strong>{{ $f['min_idle_hours'] }}h</strong> e <strong>{{ $f['max_age_days'] }} dias</strong> parada.</li>
                <li>Se o cliente só se despediu ("obrigado", "valeu", "ok", 👍 etc.), não gera sugestão — a conversa terminou bem.</li>
                <li>Isso roda sozinho <strong>1x por dia, às 18h</strong>.</li>
            </ul>
        </div>

        <div>
            <h3 class="mb-1 font-semibold text-slate-900 dark:text-slate-100">As abas</h3>
            <ul class="list-inside list-disc space-y-1">
                <li><strong>Em andamento</strong> — lead aberto, sem sugestão esperando decisão agora.</li>
                <li><strong>Reconectar</strong> — lead aberto com sugestão pendente: precisa decidir.</li>
                <li><strong>Pós-venda</strong> — marcado como "Comprou".</li>
                <li><strong>Desistiu</strong> — marcado como "Perdeu". Depois de <strong>{{ $f['lost_reconnect_after_days'] }} dias</strong>, o sistema sugere tentar reconectar de novo sozinho.</li>
                <li><strong>Todos</strong> — soma de tudo, sem filtro.</li>
            </ul>
        </div>

        <div>
            <h3 class="mb-1 font-semibold text-slate-900 dark:text-slate-100">O que cada botão faz</h3>
            <ul class="list-inside list-disc space-y-1">
                <li><strong>Aprovar</strong> — libera o botão "Copiar link" (copia o link do WhatsApp com a mensagem pronta; cole na janela do Chrome da loja certa e mande na mão — nada é enviado sozinho).</li>
                <li><strong>Copiar link</strong> — copia o link pra área de transferência (não abre direto, pra evitar mandar pela conta errada). Cole na janela do Chrome da loja certa.</li>
                <li><strong>Desfazer aprovação</strong> — volta a sugestão pro estado de antes de aprovar, caso tenha clicado "Aprovar" sem querer.</li>
                <li><strong>Separar</strong> — pra quando a resposta do cliente é ambígua (nem desistiu, nem comprou ainda, tipo "vou ver e te falo"). Cancela só essa sugestão, o lead volta pra "Em andamento"; pode ganhar uma sugestão nova depois de <strong>{{ $f['cooldown_days'] }} dias</strong>, até <strong>{{ $f['max_attempts'] }} tentativas</strong> no total.</li>
                <li><strong>Comprou</strong> — vai direto pra "Pós-venda".</li>
                <li><strong>Perdeu</strong> — vai direto pra "Desistiu" (e pode ser reconectado automaticamente depois, como explicado acima).</li>
                <li><strong>Deletar</strong> — pra contato que não é cliente de verdade (fornecedor, número de teste, grupo fora do assunto). Marca o chat como ignorado pra sempre (nunca mais vira lead nem reconexão automática) e some do painel na hora. Sem desfazer pela tela.</li>
            </ul>
        </div>

        <div>
            <h3 class="mb-1 font-semibold text-slate-900 dark:text-slate-100">Selo de prioridade (🔥 🟡 ⚪)</h3>
            <p class="mb-1">Na aba "Reconectar", os leads vêm ordenados por quem parece mais perto de comprar, não só por quem escreveu mais recente. O selo passa o mouse em cima (ou toca, no celular) pra ver o motivo:</p>
            <ul class="list-inside list-disc space-y-1">
                <li><span class="rounded-full bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-950 dark:text-red-300">🔥 Alta</span> — já recebeu orçamento, ou perguntou algo de fechamento (preço, parcelamento, entrega, garantia...).</li>
                <li><span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300">🟡 Média</span> — citou um produto específico ou fez alguma pergunta direta, sem chegar a falar de fechamento.</li>
                <li><span class="rounded-full bg-slate-200 px-1.5 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-400">⚪ Baixa</span> — conversa parada sem sinal forte de interesse ainda (ex: só um "oi").</li>
            </ul>
            <p class="mt-1">É calculado por regras (sem IA), olhando as últimas mensagens do cliente — não muda o que vira lead, só a ordem de quem atender primeiro.</p>
        </div>

        <div>
            <h3 class="mb-1 font-semibold text-slate-900 dark:text-slate-100">Quem pode decidir</h3>
            <p>Só pessoas autorizadas (hoje: Matheus e Caio) podem aprovar, descartar, marcar comprou ou perdeu. Quem não tem permissão só visualiza.</p>
        </div>
    </div>
</details>
