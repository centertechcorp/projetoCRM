@php
    $followup = $lead->followups->first();
    $rowClass = fn (string $base = '') => trim(($ajax ? 'js-row-action ' : '').$base);
@endphp
@if ($canDecide)
    <div class="flex flex-col gap-1.5">
        @if ($followup && in_array($followup->status, ['candidate', 'draft_ready']))
            <form method="POST" action="{{ route('painel.followups.approve', $followup) }}" class="{{ $rowClass() }}">
                @csrf
                <button data-tooltip="Aprova essa sugestão e libera o botão &quot;Copiar link&quot; com a mensagem pronta." class="w-full rounded-full border border-blue-200 px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 dark:border-cyan-400 dark:bg-slate-950 dark:text-cyan-300 dark:transition-all dark:duration-200 dark:hover:bg-[rgba(34,211,238,0.1)] dark:hover:-translate-y-[3px] dark:hover:shadow-[0_6px_20px_rgba(34,211,238,0.4)]">Aprovar</button>
            </form>
            <form method="POST" action="{{ route('painel.followups.dismiss', $followup) }}" class="{{ $rowClass() }}">
                @csrf
                <button data-tooltip="Cancela só essa sugestão. O lead continua aberto e volta pra &quot;Em andamento&quot;." class="w-full rounded-full border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50 dark:border-slate-400 dark:bg-slate-950 dark:text-slate-300 dark:transition-all dark:duration-200 dark:hover:bg-[rgba(148,163,184,0.1)] dark:hover:-translate-y-[3px] dark:hover:shadow-[0_6px_20px_rgba(148,163,184,0.4)]">Separar</button>
            </form>
        @endif

        @if ($followup && $followup->status === 'approved')
            <button type="button" data-copy-link="https://web.whatsapp.com/send?phone={{ $lead->customer->phone }}{{ $followup->suggested_message ? '&text='.urlencode($followup->suggested_message) : '' }}" data-store="{{ $lead->store->code }}"
                data-tooltip="Copia o link do WhatsApp com a mensagem pronta — cole na janela do Chrome da loja {{ $lead->store->code }}."
                class="w-full rounded-full border border-emerald-300 px-2 py-1 text-center text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:border-emerald-400 dark:bg-slate-950 dark:text-emerald-300 dark:transition-all dark:duration-200 dark:hover:bg-[rgba(52,211,153,0.1)] dark:hover:-translate-y-[3px] dark:hover:shadow-[0_6px_20px_rgba(52,211,153,0.4)]">
                Copiar link ({{ $lead->store->code }})
            </button>
            <form method="POST" action="{{ route('painel.followups.unapprove', $followup) }}" class="{{ $rowClass() }}">
                @csrf
                <button data-tooltip="Desfaz a aprovação, volta a sugestão pro estado de antes." class="w-full rounded-full border border-slate-300 px-2 py-1 text-xs text-slate-500 hover:bg-slate-50 dark:border-slate-400 dark:bg-slate-950 dark:text-slate-300 dark:transition-all dark:duration-200 dark:hover:bg-[rgba(148,163,184,0.1)] dark:hover:-translate-y-[3px] dark:hover:shadow-[0_6px_20px_rgba(148,163,184,0.4)]">Desfazer aprovação</button>
            </form>
        @endif

        @if ($lead->status === 'open')
            <form method="POST" action="{{ route('painel.leads.won', $lead) }}" class="{{ $rowClass() }}">
                @csrf
                <button data-tooltip="Fecha o lead como vendido — vai pra aba &quot;Pós-venda&quot;." class="w-full rounded-full border border-emerald-300 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50 dark:border-emerald-400 dark:bg-slate-950 dark:text-emerald-300 dark:transition-all dark:duration-200 dark:hover:bg-[rgba(52,211,153,0.1)] dark:hover:-translate-y-[3px] dark:hover:shadow-[0_6px_20px_rgba(52,211,153,0.4)]">Comprou</button>
            </form>
            <form method="POST" action="{{ route('painel.leads.lost', $lead) }}" class="{{ $rowClass() }}">
                @csrf
                <button data-tooltip="Fecha o lead como perdido — vai pra aba &quot;Desistiu&quot;. Pode ser reabordado sozinho depois de alguns dias." class="w-full rounded-full border border-slate-300 px-2 py-1 text-xs text-slate-500 hover:bg-slate-50 dark:border-slate-400 dark:bg-slate-950 dark:text-slate-300 dark:transition-all dark:duration-200 dark:hover:bg-[rgba(148,163,184,0.1)] dark:hover:-translate-y-[3px] dark:hover:shadow-[0_6px_20px_rgba(148,163,184,0.4)]">Perdeu</button>
            </form>
        @endif

        <form method="POST" action="{{ route('painel.leads.delete', $lead) }}" class="{{ $ajax ? 'js-row-action js-row-delete' : 'js-confirm-delete' }}">
            @csrf
            <button data-tooltip="Ignora esse contato pra sempre (nunca mais vira lead) e some do painel na hora." class="w-full rounded-full border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-400 dark:bg-slate-950 dark:text-red-300 dark:transition-all dark:duration-200 dark:hover:bg-[rgba(248,113,113,0.1)] dark:hover:-translate-y-[3px] dark:hover:shadow-[0_6px_20px_rgba(248,113,113,0.4)]">Deletar</button>
        </form>
    </div>
@else
    <span class="text-slate-400 dark:text-slate-500">—</span>
@endif
