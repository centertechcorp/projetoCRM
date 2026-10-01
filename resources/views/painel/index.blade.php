@extends('layouts.app')

@section('title', 'Leads')

@php
    $tabLabel = [
        'ongoing' => 'Em andamento',
        'reconnect' => 'Reconectar',
        'won' => 'Pós-venda',
        'lost' => 'Desistiu',
        'all' => 'Todos',
    ];
    $statusBadge = [
        'open' => 'bg-amber-100 text-amber-800',
        'won' => 'bg-emerald-100 text-emerald-800',
        'lost' => 'bg-slate-200 text-slate-600',
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
@endphp

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <nav class="flex flex-wrap gap-1 rounded-lg bg-slate-200/60 p-1 text-sm">
        @foreach ($tabs as $t)
            <a href="{{ route('painel.index', ['tab' => $t, 'store' => $selectedStore]) }}"
                class="rounded-md px-3 py-1.5 font-medium {{ $tab === $t ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                {{ $tabLabel[$t] }} <span class="text-slate-400">({{ $counts[$t] }})</span>
            </a>
        @endforeach
    </nav>

    <div class="flex items-center gap-2">
        <a href="{{ route('painel.report', ['store' => $selectedStore]) }}"
            class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Relatório do dia
        </a>

        @if ($stores->isNotEmpty())
            <form method="GET" class="text-sm">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <select name="store" onchange="this.form.submit()"
                    class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">Todas as lojas</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}" @selected((string) $selectedStore === (string) $store->id)>{{ $store->code }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>
</div>

@if ($leads->isEmpty())
    <p class="rounded-lg border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500">
        Nenhum lead nesta aba ainda.
    </p>
@else
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Cliente</th>
                    <th class="px-4 py-3">Loja</th>
                    <th class="px-4 py-3">Produto / Orçamento</th>
                    <th class="px-4 py-3">Origem · Atendente</th>
                    <th class="px-4 py-3">Próximo contato</th>
                    <th class="px-4 py-3">Sugestão de reabordagem</th>
                    <th class="px-4 py-3">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($leads as $lead)
                    @php $followup = $lead->followups->first(); @endphp
                    <tr class="align-top">
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-900">{{ $lead->customer->name ?: '(sem nome)' }}</div>
                            <div class="text-slate-500">{{ $lead->customer->phone }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-900">{{ $lead->store->code }}</div>
                            <span class="mt-1 inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge[$lead->status] }}">
                                {{ $statusLabel[$lead->status] ?? $lead->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div>{{ $lead->product_interest ?: '—' }}</div>
                            @if ($lead->quoted_amount)
                                <div class="text-slate-500">R$ {{ number_format($lead->quoted_amount, 2, ',', '.') }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500">
                            {{ $lead->source }}@if ($lead->attended_by_label) · {{ $lead->attended_by_label }} @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500">
                            {{ $lead->next_contact_at?->format('d/m H:i') ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            @if ($followup)
                                <div class="mb-1 text-xs font-medium text-slate-500">
                                    {{ $reasonLabel[$followup->reason] ?? $followup->reason }} · {{ $followup->status }}
                                </div>
                                @if ($followup->suggested_message)
                                    <p class="max-w-xs text-slate-700">{{ $followup->suggested_message }}</p>
                                @else
                                    <p class="max-w-xs italic text-slate-400">Sem rascunho ainda — escreva a mensagem você mesmo ao abrir o WhatsApp.</p>
                                @endif
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($canDecide)
                                <div class="flex flex-col gap-1.5">
                                    @if ($followup && in_array($followup->status, ['candidate', 'draft_ready']))
                                        <form method="POST" action="{{ route('painel.followups.approve', $followup) }}">
                                            @csrf
                                            <button class="w-full rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white hover:bg-slate-700">Aprovar</button>
                                        </form>
                                        <form method="POST" action="{{ route('painel.followups.dismiss', $followup) }}">
                                            @csrf
                                            <button class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50">Descartar</button>
                                        </form>
                                    @endif

                                    @if ($followup && $followup->status === 'approved')
                                        <a target="_blank" rel="noopener"
                                            href="https://wa.me/{{ $lead->customer->phone }}{{ $followup->suggested_message ? '?text='.urlencode($followup->suggested_message) : '' }}"
                                            class="rounded-md border border-emerald-300 px-2 py-1 text-center text-xs font-medium text-emerald-700 hover:bg-emerald-50">
                                            Abrir no WhatsApp
                                        </a>
                                    @endif

                                    @if ($lead->status === 'open')
                                        <form method="POST" action="{{ route('painel.leads.won', $lead) }}">
                                            @csrf
                                            <button class="w-full rounded-md border border-emerald-300 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50">Comprou</button>
                                        </form>
                                        <form method="POST" action="{{ route('painel.leads.lost', $lead) }}">
                                            @csrf
                                            <button class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs text-slate-500 hover:bg-slate-50">Perdeu</button>
                                        </form>
                                    @endif
                                </div>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="mt-6">
    @include('painel.partials.business-rules')
</div>
@endsection
