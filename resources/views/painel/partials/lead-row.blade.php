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
<tr class="align-top" data-lead-row="{{ $lead->id }}">
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
        {{ $lead->last_contact_at?->format('d/m H:i') ?? '—' }}
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
        @include('painel.partials.lead-actions', ['lead' => $lead, 'canDecide' => $canDecide, 'ajax' => true])
    </td>
</tr>
