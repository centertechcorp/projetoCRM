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
        @if ($ranked)
            <input type="hidden" name="rank" value="0">
            <button type="submit" title="Volta pra ordem cronológica (mais recente primeiro)."
                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                Voltar pra ordem cronológica
            </button>
        @else
            <input type="hidden" name="rank" value="1">
            <button type="submit" title="Calcula, pra esse dia, quais conversas parecem mais perto de fechar — do mais forte pro mais fraco."
                class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                Gerar leads do dia
            </button>
        @endif
    </form>
</div>

<div id="reportResults">
    @include('painel.partials.report-results', [
        'search' => $search, 'date' => $date, 'summary' => $summary,
        'messages' => $messages, 'conversations' => $conversations, 'ranked' => $ranked,
    ])
</div>

<script>
    let flashHideTimer;

    function bindCopyButtons(scope) {
        scope.querySelectorAll('[data-copy-link]').forEach(function (button) {
            button.addEventListener('click', function () {
                navigator.clipboard.writeText(button.dataset.copyLink).then(function () {
                    const original = button.textContent;
                    button.textContent = 'Copiado!';
                    setTimeout(function () {
                        button.textContent = original;
                    }, 1500);

                    const flash = document.getElementById('flashStatus');
                    if (flash) {
                        flash.textContent = 'Link copiado! Cole na janela do Chrome da loja ' + button.dataset.store + '.';
                        flash.classList.remove('hidden');

                        clearTimeout(flashHideTimer);
                        flashHideTimer = setTimeout(function () {
                            flash.classList.add('hidden');
                        }, 20000);
                    }
                });
            });
        });
    }

    bindCopyButtons(document);

    (function () {
        const input = document.getElementById('reportSearchInput');
        const results = document.getElementById('reportResults');

        if (!input || !results) {
            return;
        }

        let debounceTimer;

        input.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(liveSearch, 400);
        });

        function liveSearch() {
            const params = new URLSearchParams(new FormData(input.form));
            const url = window.location.pathname + '?' + params.toString();

            fetch(url, { headers: { Accept: 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    results.innerHTML = data.html;
                    bindCopyButtons(results);
                    history.replaceState(null, '', url);
                });
        }
    })();
</script>
@endsection
