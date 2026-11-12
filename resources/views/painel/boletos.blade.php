@extends('layouts.app')

@section('title', 'Clientes de boleto')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        {{-- Volta para onde a pessoa estava (sem recarregar) se veio de outra página do CRM; senão vai pro Painel. --}}
        <a href="{{ route('painel.index') }}" id="boletoBack"
            onclick="try { if (history.length > 1 && document.referrer && new URL(document.referrer).origin === location.origin) { event.preventDefault(); history.back(); } } catch (e) {}"
            class="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">&larr; Voltar</a>
        <h1 class="text-lg font-semibold dark:text-slate-100">Clientes de boleto</h1>
    </div>
    <p class="text-sm text-slate-500 dark:text-slate-400">
        {{ $clients->count() }} {{ $clients->count() === 1 ? 'cliente' : 'clientes' }} que falaram de boleto — o histórico fica sempre aqui.
    </p>
</div>

@if ($clients->isEmpty())
    <div class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
        Nenhum cliente falou de boleto ainda.
    </div>
@else
    <div class="space-y-3" id="boletoList" data-latest-id="{{ $latestId }}">
        @foreach ($clients as $client)
            @php
                $chat = $client['chat'];
                $last = $client['last_message'];
                $name = $chat->display_name ?: ($chat->phone ?: 'Sem nome');
                $search = $chat->phone ?: $chat->display_name;
            @endphp
            <div class="boleto-card rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"
                data-max-id="{{ $client['max_id'] }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-slate-900 dark:text-slate-100">{{ $name }}</span>
                        <span class="boleto-new hidden rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">novo</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $client['store']->code }}</span>
                        @if ($chat->phone)
                            <span class="text-xs text-slate-500 dark:text-slate-400">{{ $chat->phone }}</span>
                        @endif
                    </div>
                    <span class="text-xs text-slate-500 dark:text-slate-400">
                        {{ $client['mentions'] }} {{ $client['mentions'] === 1 ? 'mensagem' : 'mensagens' }} · última em {{ $last->sent_at->format('d/m/Y H:i') }}
                    </span>
                </div>
                <p class="mt-2 break-words text-sm text-slate-700 dark:text-slate-300">“{{ \Illuminate\Support\Str::limit($last->body, 220) }}”</p>
                @if ($search)
                    <a href="{{ route('painel.report', ['search' => $search]) }}"
                        class="mt-3 inline-block text-sm font-medium text-slate-700 underline underline-offset-2 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                        Ver conversa
                    </a>
                @endif
            </div>
        @endforeach
    </div>
@endif

<script>
    (function () {
        const list = document.getElementById('boletoList');
        const latest = list ? parseInt(list.dataset.latestId, 10) || 0 : 0;
        let seen = 0;

        try { seen = parseInt(localStorage.getItem('boleto_seen_id'), 10) || 0; } catch (e) {}

        // Destaca o que chegou depois da última vez que este navegador viu o aviso.
        document.querySelectorAll('.boleto-card').forEach(function (card) {
            if (seen > 0 && parseInt(card.dataset.maxId, 10) > seen) {
                card.querySelector('.boleto-new')?.classList.remove('hidden');
            }
        });

        // Abrir esta página conta como "visto".
        try { if (latest > seen) localStorage.setItem('boleto_seen_id', String(latest)); } catch (e) {}
    })();
</script>
@endsection
