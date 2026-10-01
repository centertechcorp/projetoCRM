@php
    $f = config('whatsapp.followup');
@endphp

<details class="mb-6 rounded-xl border border-slate-200 bg-white shadow-sm">
    <summary class="cursor-pointer select-none rounded-xl px-4 py-3 text-sm font-medium text-slate-700 hover:bg-slate-50">
        Como funciona / Regras do sistema
    </summary>

    <div class="space-y-5 border-t border-slate-200 px-4 py-4 text-sm text-slate-700">
        <div>
            <h3 class="mb-1 font-semibold text-slate-900">Quando uma conversa vira lead sozinha</h3>
            <ul class="list-inside list-disc space-y-1">
                <li>Só conta conversa <strong>individual</strong> — grupo nunca vira lead.</li>
                <li>A última mensagem (de qualquer lado) precisa estar entre <strong>{{ $f['min_idle_hours'] }}h</strong> e <strong>{{ $f['max_age_days'] }} dias</strong> parada.</li>
                <li>Se o cliente só se despediu ("obrigado", "valeu", "ok", 👍 etc.), não gera sugestão — a conversa terminou bem.</li>
                <li>Isso roda sozinho <strong>1x por dia, às 18h</strong>.</li>
            </ul>
        </div>

        <div>
            <h3 class="mb-1 font-semibold text-slate-900">As abas</h3>
            <ul class="list-inside list-disc space-y-1">
                <li><strong>Em andamento</strong> — lead aberto, sem sugestão esperando decisão agora.</li>
                <li><strong>Reconectar</strong> — lead aberto com sugestão pendente: precisa decidir.</li>
                <li><strong>Pós-venda</strong> — marcado como "Comprou".</li>
                <li><strong>Desistiu</strong> — marcado como "Perdeu". Depois de <strong>{{ $f['lost_reconnect_after_days'] }} dias</strong>, o sistema sugere tentar reconectar de novo sozinho.</li>
                <li><strong>Todos</strong> — soma de tudo, sem filtro.</li>
            </ul>
        </div>

        <div>
            <h3 class="mb-1 font-semibold text-slate-900">O que cada botão faz</h3>
            <ul class="list-inside list-disc space-y-1">
                <li><strong>Aprovar</strong> — libera o botão "Abrir no WhatsApp" (você escreve e manda a mensagem na mão, nada é enviado sozinho).</li>
                <li><strong>Descartar</strong> — só cancela essa sugestão específica. O lead continua existindo e volta pra "Em andamento"; pode ganhar uma sugestão nova depois de <strong>{{ $f['cooldown_days'] }} dias</strong>, até <strong>{{ $f['max_attempts'] }} tentativas</strong> no total.</li>
                <li><strong>Comprou</strong> — vai direto pra "Pós-venda".</li>
                <li><strong>Perdeu</strong> — vai direto pra "Desistiu" (e pode ser reconectado automaticamente depois, como explicado acima).</li>
            </ul>
        </div>

        <div>
            <h3 class="mb-1 font-semibold text-slate-900">Quem pode decidir</h3>
            <p>Só pessoas autorizadas (hoje: Matheus e Caio) podem aprovar, descartar, marcar comprou ou perdeu. Quem não tem permissão só visualiza.</p>
        </div>
    </div>
</details>
