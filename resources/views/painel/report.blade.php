@extends('layouts.app')

@section('title', 'Relatório do dia')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <a href="{{ route('painel.index') }}" class="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">&larr; Voltar</a>
        <h1 class="text-lg font-semibold dark:text-slate-100">Relatório — {{ $date->format('d/m/Y') }}</h1>
    </div>

    <form method="GET" class="flex items-center gap-2 text-sm">
        <input type="search" name="search" id="reportSearchInput" value="{{ $search }}" placeholder="Nome, telefone ou mensagem"
            class="w-64 rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500" autocomplete="off">
        <input type="date" name="date" value="{{ $date->format('Y-m-d') }}" onchange="this.form.submit()"
            class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
        @if ($stores->isNotEmpty())
            <select name="store" onchange="this.form.submit()"
                class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                <option value="">Todas as lojas</option>
                @foreach ($stores as $store)
                    <option value="{{ $store->id }}" @selected((string) $selectedStore === (string) $store->id)>{{ $store->code }}</option>
                @endforeach
            </select>
        @endif
    </form>
</div>

<div class="mb-6 flex flex-wrap gap-3">
    @forelse ($summary as $loja => $counts)
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $loja }}</div>
            <div class="mt-1 text-sm text-slate-700 dark:text-slate-300">
                <span class="font-semibold text-slate-900 dark:text-slate-100">{{ $counts['recebidas'] }}</span> recebidas ·
                <span class="font-semibold text-slate-900 dark:text-slate-100">{{ $counts['enviadas'] }}</span> enviadas
            </div>
        </div>
    @empty
        <p class="text-sm text-slate-500 dark:text-slate-400">Nenhuma mensagem individual nesse dia.</p>
    @endforelse
</div>

@if ($messages->isEmpty())
    <p class="rounded-lg border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
        Nenhuma mensagem nesse dia.
    </p>
@else
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                <tr>
                    <th class="px-4 py-3">Hora</th>
                    <th class="px-4 py-3">Loja</th>
                    <th class="px-4 py-3">Contato</th>
                    <th class="px-4 py-3">Direção</th>
                    <th class="px-4 py-3">Mensagem</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($conversations as $i => $conversation)
                    @php
                        $chat = $conversation['chat'];
                        $contactSearch = mb_strtolower(($chat->display_name ?? '').' '.($chat->phone ?? ''));
                        $last = $conversation['messages']->last();
                    @endphp
                    <tr data-report-search="{{ $contactSearch }}">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500 dark:text-slate-400">{{ $conversation['last_sent_at']->format('H:i') }}</td>
                        <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ $conversation['store']->code }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <div class="text-slate-900 dark:text-slate-100">{{ $chat->display_name ?: '(sem nome)' }}</div>
                            <div class="text-slate-500 dark:text-slate-400">{{ $chat->phone ?? $chat->chat_key }}</div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300">{{ $conversation['recebidas'] }} recebidas</span>
                            <span class="mt-1 block rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 w-fit">{{ $conversation['enviadas'] }} enviadas</span>
                        </td>
                        <td class="max-w-md px-4 py-3 text-slate-700 dark:text-slate-300">
                            <details>
                                <summary class="cursor-pointer select-none">
                                    <span class="text-slate-400 dark:text-slate-500">({{ $conversation['messages']->count() }})</span>
                                    {{ $last->body !== '' ? $last->body : '(' . $last->type . ', sem texto)' }}
                                </summary>
                                <ul class="mt-2 space-y-3.5 border-l-2 border-slate-200 pl-3 dark:border-slate-700">
                                    @foreach ($conversation['messages'] as $message)
                                        <li>
                                            <span class="text-xs text-slate-400 dark:text-slate-500">{{ $message->sent_at->format('H:i') }}</span>
                                            @if ($message->direction === 'in')
                                                <span class="rounded-full bg-amber-100 px-0.5 py-0 text-[10px] font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300">Recebida</span>
                                            @else
                                                <span class="rounded-full bg-emerald-100 px-0.5 py-0 text-[10px] font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">Enviada</span>
                                            @endif
                                            {{ $message->body !== '' ? $message->body : '(' . $message->type . ', sem texto)' }}
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<script>
    (function () {
        const input = document.getElementById('reportSearchInput');
        const rows = document.querySelectorAll('[data-report-search]');

        if (!input || rows.length === 0) {
            return;
        }

        input.addEventListener('input', function () {
            const term = input.value.trim().toLowerCase();

            rows.forEach(function (row) {
                row.style.display = row.dataset.reportSearch.includes(term) ? '' : 'none';
            });
        });
    })();
</script>
@endsection
