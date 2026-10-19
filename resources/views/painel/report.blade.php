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
        'canDecide' => $canDecide,
    ])
</div>

<div class="mt-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Ações no período</h2>
        <div class="flex items-center gap-2 text-sm">
            <label class="text-slate-500 dark:text-slate-400">De
                <input type="date" id="actionsFrom" value="{{ $chartDefaultFrom }}"
                    class="ml-1 rounded-lg border border-slate-300 px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            </label>
            <label class="text-slate-500 dark:text-slate-400">até
                <input type="date" id="actionsTo" value="{{ $chartDefaultTo }}"
                    class="ml-1 rounded-lg border border-slate-300 px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            </label>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-6">
        <div class="h-56 w-56 flex-shrink-0">
            <canvas id="actionsDonutChart"></canvas>
        </div>
        <ul id="actionsLegend" class="flex-1 space-y-2 text-sm"></ul>
    </div>
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

    function bindConfirmDelete(scope) {
        scope.querySelectorAll('form.js-confirm-delete').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!confirm('Tem certeza que deseja deletar esse contato?')) {
                    event.preventDefault();
                }
            });
        });
    }

    bindCopyButtons(document);
    bindConfirmDelete(document);

    document.addEventListener('DOMContentLoaded', setupActionsChart);

    function setupActionsChart() {
        const canvas = document.getElementById('actionsDonutChart');

        if (!canvas || !window.Chart) {
            return;
        }

        const fromInput = document.getElementById('actionsFrom');
        const toInput = document.getElementById('actionsTo');
        const legend = document.getElementById('actionsLegend');
        const storeSelect = document.querySelector('select[name="store"]');
        const colors = ['#94a3b8', '#10b981', '#ef4444', '#8b5cf6'];
        let chart = null;

        function load() {
            const params = new URLSearchParams({ from: fromInput.value, to: toInput.value });

            if (storeSelect && storeSelect.value) {
                params.set('store', storeSelect.value);
            }

            fetch('{{ route('painel.report.actions') }}?' + params.toString(), { headers: { Accept: 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    const total = data.counts.reduce(function (a, b) { return a + b; }, 0);

                    if (chart) {
                        chart.data.labels = data.labels;
                        chart.data.datasets[0].data = data.counts;
                        chart.update();
                    } else {
                        chart = new Chart(canvas, {
                            type: 'doughnut',
                            data: {
                                labels: data.labels,
                                datasets: [{ data: data.counts, backgroundColor: colors, borderWidth: 0 }],
                            },
                            options: {
                                cutout: '65%',
                                plugins: { legend: { display: false } },
                            },
                        });
                    }

                    legend.innerHTML = data.labels.map(function (label, i) {
                        const count = data.counts[i];
                        const pct = total > 0 ? ((count / total) * 100).toFixed(1) : '0.0';

                        return '<li class="flex items-center gap-2">'
                            + '<span class="h-2.5 w-2.5 rounded-full" style="background:' + colors[i] + '"></span>'
                            + '<span class="text-slate-700 dark:text-slate-300">' + label + '</span>'
                            + '<span class="ml-auto font-medium text-slate-900 dark:text-slate-100">' + count + '</span>'
                            + '<span class="w-14 text-right text-slate-400 dark:text-slate-500">(' + pct + '%)</span>'
                            + '</li>';
                    }).join('');
                });
        }

        fromInput.addEventListener('change', load);
        toInput.addEventListener('change', load);

        if (storeSelect) {
            storeSelect.addEventListener('change', load);
        }

        load();
    }

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
                    bindConfirmDelete(results);
                    history.replaceState(null, '', url);
                });
        }
    })();
</script>
@endsection
