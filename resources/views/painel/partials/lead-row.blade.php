@php
    $statusBadge = [
        'open' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
        'won' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
        'lost' => 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
    ];
    $statusLabel = [
        'open' => 'Aberto',
        'won' => 'Vendido',
        'lost' => 'Perdido',
    ];
    $reasonLabel = [
        'customer_unanswered' => 'Cliente sem resposta',
        'customer_silent' => 'Cliente sumiu',
        'purchase' => 'Pós-venda',
    ];
    $followup = $lead->followups->first();

    $priorityScorer = app(\App\Services\Leads\LeadPriorityScorer::class);
    $priorityScore = $lead->status === 'open' ? $priorityScorer->score($lead) : null;
    $priorityLabel = $priorityScore === null ? null : $priorityScorer->label($priorityScore);
    $priorityReason = $priorityScore === null ? null : $priorityScorer->reason($lead);
    $priorityBadge = [
        'Alta' => 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
        'Média' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
        'Baixa' => 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
    ];
    $priorityIcon = ['Alta' => '🔥', 'Média' => '🟡', 'Baixa' => '⚪'];
@endphp
<tr class="align-top" data-lead-row="{{ $lead->id }}" data-lead-search="{{ mb_strtolower($lead->customer->name.' '.$lead->customer->phone.' '.$lead->product_interest) }}">
    <td class="px-4 py-3">
        <div class="font-medium text-slate-900 dark:text-slate-100">{{ $lead->customer->name ?: '(sem nome)' }}</div>
        <div class="text-slate-500 dark:text-slate-400">{{ $lead->customer->phone }}</div>
    </td>
    <td class="px-4 py-3">
        <div class="font-medium text-slate-900 dark:text-slate-100">{{ $lead->store->code }}</div>
        <span class="mt-1 inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge[$lead->status] }}">
            {{ $statusLabel[$lead->status] ?? $lead->status }}
        </span>
    </td>
    <td class="px-4 py-3">
        <div class="dark:text-slate-100">{{ $lead->product_interest ?: '—' }}</div>
        @if ($lead->quoted_amount)
            <div class="text-slate-500 dark:text-slate-400">R$ {{ number_format($lead->quoted_amount, 2, ',', '.') }}</div>
        @endif
    </td>
    <td class="px-4 py-3 text-slate-500 dark:text-slate-400">
        {{ $lead->source }}@if ($lead->attended_by_label) · {{ $lead->attended_by_label }} @endif
    </td>
    <td class="px-4 py-3 text-slate-500 dark:text-slate-400">
        {{ $lead->next_contact_at?->format('d/m H:i') ?? '—' }}
    </td>
    <td class="px-4 py-3">
        <div class="mb-1 flex items-center gap-1.5">
            @if ($priorityLabel)
                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $priorityBadge[$priorityLabel] }}"
                    @if ($priorityReason) title="{{ $priorityReason }}" @endif>
                    {{ $priorityIcon[$priorityLabel] }} {{ $priorityLabel }}
                </span>
            @endif
            @if ($lead->chat)
                <a href="{{ route('painel.report', ['date' => optional($lead->last_contact_at)->format('Y-m-d'), 'store' => $lead->store_id, 'search' => $lead->chat->phone ?? $lead->customer->phone]) }}"
                    data-tooltip="Abre essa conversa no Relatório do dia, na data do último contato."
                    class="inline-flex items-center rounded-full border border-slate-300 px-2 py-0.5 text-xs text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800">
                    Visualizar
                </a>
            @endif
        </div>
        @if ($followup)
            <div class="mb-1 text-xs font-medium text-slate-500 dark:text-slate-400">
                {{ $reasonLabel[$followup->reason] ?? $followup->reason }} · {{ $followup->status }}
            </div>
            @if ($followup->suggested_message)
                <p class="max-w-xs text-slate-700 dark:text-slate-300">{{ $followup->suggested_message }}</p>
            @else
                @php $lastCustomerMessage = $lead->chat?->messages()->where('direction', 'in')->latest('sent_at')->first(); @endphp
                @if ($lastCustomerMessage)
                    <p class="max-w-xs text-slate-700 dark:text-slate-300">
                        <span class="text-slate-400 dark:text-slate-500">{{ $lastCustomerMessage->sent_at->format('d/m H:i') }} ·</span>
                        {{ $lastCustomerMessage->body !== '' ? $lastCustomerMessage->body : '('.$lastCustomerMessage->type.', sem texto)' }}
                    </p>
                @else
                    <p class="max-w-xs italic text-slate-400 dark:text-slate-500">Sem mensagem do cliente ainda.</p>
                @endif
            @endif
        @else
            <span class="text-slate-400 dark:text-slate-500">—</span>
        @endif
    </td>
    <td class="px-4 py-3">
        @if ($canDecide)
            <div class="flex flex-col gap-1.5">
                @if ($followup && in_array($followup->status, ['candidate', 'draft_ready']))
                    <form method="POST" action="{{ route('painel.followups.approve', $followup) }}" class="js-row-action">
                        @csrf
                        <button data-tooltip="Aprova essa sugestão e libera o botão &quot;Copiar link&quot; com a mensagem pronta." class="w-full rounded-full border border-blue-200 px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 dark:border-cyan-400 dark:bg-slate-950 dark:text-cyan-300 dark:transition-all dark:duration-200 dark:hover:bg-[rgba(34,211,238,0.1)] dark:hover:-translate-y-[3px] dark:hover:shadow-[0_6px_20px_rgba(34,211,238,0.4)]">Aprovar</button>
                    </form>
                    <form method="POST" action="{{ route('painel.followups.dismiss', $followup) }}" class="js-row-action">
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
                    <form method="POST" action="{{ route('painel.followups.unapprove', $followup) }}" class="js-row-action">
                        @csrf
                        <button data-tooltip="Desfaz a aprovação, volta a sugestão pro estado de antes." class="w-full rounded-full border border-slate-300 px-2 py-1 text-xs text-slate-500 hover:bg-slate-50 dark:border-slate-400 dark:bg-slate-950 dark:text-slate-300 dark:transition-all dark:duration-200 dark:hover:bg-[rgba(148,163,184,0.1)] dark:hover:-translate-y-[3px] dark:hover:shadow-[0_6px_20px_rgba(148,163,184,0.4)]">Desfazer aprovação</button>
                    </form>
                @endif

                @if ($lead->status === 'open')
                    <form method="POST" action="{{ route('painel.leads.won', $lead) }}" class="js-row-action">
                        @csrf
                        <button data-tooltip="Fecha o lead como vendido — vai pra aba &quot;Pós-venda&quot;." class="w-full rounded-full border border-emerald-300 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50 dark:border-emerald-400 dark:bg-slate-950 dark:text-emerald-300 dark:transition-all dark:duration-200 dark:hover:bg-[rgba(52,211,153,0.1)] dark:hover:-translate-y-[3px] dark:hover:shadow-[0_6px_20px_rgba(52,211,153,0.4)]">Comprou</button>
                    </form>
                    <form method="POST" action="{{ route('painel.leads.lost', $lead) }}" class="js-row-action">
                        @csrf
                        <button data-tooltip="Fecha o lead como perdido — vai pra aba &quot;Desistiu&quot;. Pode ser reabordado sozinho depois de alguns dias." class="w-full rounded-full border border-slate-300 px-2 py-1 text-xs text-slate-500 hover:bg-slate-50 dark:border-slate-400 dark:bg-slate-950 dark:text-slate-300 dark:transition-all dark:duration-200 dark:hover:bg-[rgba(148,163,184,0.1)] dark:hover:-translate-y-[3px] dark:hover:shadow-[0_6px_20px_rgba(148,163,184,0.4)]">Perdeu</button>
                    </form>
                @endif

                <form method="POST" action="{{ route('painel.leads.delete', $lead) }}" class="js-row-action js-row-delete">
                    @csrf
                    <button data-tooltip="Ignora esse contato pra sempre (nunca mais vira lead) e some do painel na hora." class="w-full rounded-full border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-400 dark:bg-slate-950 dark:text-red-300 dark:transition-all dark:duration-200 dark:hover:bg-[rgba(248,113,113,0.1)] dark:hover:-translate-y-[3px] dark:hover:shadow-[0_6px_20px_rgba(248,113,113,0.4)]">Deletar</button>
                </form>
            </div>
        @else
            <span class="text-slate-400 dark:text-slate-500">—</span>
        @endif
    </td>
</tr>
