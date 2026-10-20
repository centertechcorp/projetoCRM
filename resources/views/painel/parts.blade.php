@extends('layouts.app')

@section('title', 'Peças')

@section('content')
<div class="mb-6 flex items-center gap-3">
    <a href="{{ route('painel.index') }}" class="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">&larr; Voltar</a>
    <h1 class="text-lg font-semibold dark:text-slate-100">🔧 Peças (Mix Atacado)</h1>
</div>

@if ($partsByCategory)
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <input type="search" id="partsSearchInput" placeholder="Buscar peça (ex: iphone 13)" autocomplete="off"
            class="mb-3 w-full max-w-xs rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500">

        <div id="partsSearchResults" class="hidden mb-2 max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-xs dark:divide-slate-800 dark:border-slate-700"></div>

        <details id="partsSummaryTable" open>
            <summary class="cursor-pointer select-none text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">
                Ver tabela de preços
            </summary>

            <div class="mt-2 flex items-center justify-center gap-3">
                <button type="button" id="partsPrevCategory" aria-label="Categoria anterior"
                    class="flex h-7 w-16 items-center justify-center rounded-full border border-slate-300 text-base leading-none text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">‹</button>
                <span id="partsCategoryLabel" class="min-w-[9rem] text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400"></span>
                <button type="button" id="partsNextCategory" aria-label="Próxima categoria"
                    class="flex h-7 w-16 items-center justify-center rounded-full border border-slate-300 text-base leading-none text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">›</button>
            </div>

            @foreach ($partsByCategory as $categoria => $linhas)
                <div class="parts-category-page {{ $loop->first ? '' : 'hidden' }} mt-2 overflow-hidden rounded-lg border border-slate-200 dark:border-slate-800" data-category="{{ $categoria }}">
                    <table class="min-w-full text-xs">
                        <thead class="bg-slate-50 text-left font-medium uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                            <tr>
                                <th class="px-4 py-1">Marca</th>
                                <th class="py-1 pl-[5.5rem] pr-4 text-center">Qtd</th>
                                <th class="px-4 py-1 text-right">R$</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white dark:divide-slate-800 dark:bg-slate-900">
                            @foreach ($linhas as $row)
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                    <td class="px-4 py-1">
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $row['marca'] }}</span>
                                    </td>
                                    <td class="py-1 pl-[5.5rem] pr-4 text-center text-slate-500 dark:text-slate-400">{{ $row['qtd'] }}</td>
                                    <td class="px-4 py-1 text-right font-medium text-slate-900 dark:text-slate-100">
                                        R$ {{ number_format($row['min'], 2, ',', '.') }}@if ($row['min'] != $row['max']) <span class="text-slate-400 dark:text-slate-500">–</span> {{ number_format($row['max'], 2, ',', '.') }}@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </details>
    </div>
@else
    <p class="rounded-lg border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
        Tabela de peças indisponível no momento.
    </p>
@endif

<script>
    (function () {
        const input = document.getElementById('partsSearchInput');
        const resultsBox = document.getElementById('partsSearchResults');
        const summaryTable = document.getElementById('partsSummaryTable');

        if (!input || !resultsBox || !summaryTable) {
            return;
        }

        let debounceTimer = null;

        function showSummary() {
            resultsBox.classList.add('hidden');
            resultsBox.innerHTML = '';
            summaryTable.classList.remove('hidden');
        }

        function renderResults(items) {
            resultsBox.innerHTML = '';

            if (items.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'px-3 py-2 text-slate-400 dark:text-slate-500';
                empty.textContent = 'Nada encontrado.';
                resultsBox.appendChild(empty);

                return;
            }

            items.forEach(function (item) {
                const row = document.createElement('div');
                row.className = 'flex items-center justify-between gap-3 px-3 py-1.5';

                const name = document.createElement('span');
                name.className = 'text-slate-700 dark:text-slate-300';
                name.textContent = item.nome;

                const price = document.createElement('span');
                price.className = 'whitespace-nowrap text-slate-500 dark:text-slate-400';
                price.textContent = 'R$ ' + item.valor_venda.toFixed(2).replace('.', ',');

                row.appendChild(name);
                row.appendChild(price);
                resultsBox.appendChild(row);
            });
        }

        input.addEventListener('input', function () {
            const term = input.value.trim();
            clearTimeout(debounceTimer);

            if (term === '') {
                showSummary();

                return;
            }

            debounceTimer = setTimeout(function () {
                fetch('{{ route('painel.parts.search') }}?q=' + encodeURIComponent(term))
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        summaryTable.classList.add('hidden');
                        resultsBox.classList.remove('hidden');
                        renderResults(data.results);
                    });
            }, 300);
        });
    })();

    (function () {
        const pages = Array.from(document.querySelectorAll('.parts-category-page'));
        const label = document.getElementById('partsCategoryLabel');
        const prevBtn = document.getElementById('partsPrevCategory');
        const nextBtn = document.getElementById('partsNextCategory');

        if (pages.length === 0 || !label || !prevBtn || !nextBtn) {
            return;
        }

        let current = 0;

        function render() {
            pages.forEach(function (page, i) {
                page.classList.toggle('hidden', i !== current);
            });
            label.textContent = pages[current].dataset.category + ' (' + (current + 1) + '/' + pages.length + ')';
        }

        prevBtn.addEventListener('click', function () {
            current = (current - 1 + pages.length) % pages.length;
            render();
        });

        nextBtn.addEventListener('click', function () {
            current = (current + 1) % pages.length;
            render();
        });

        render();
    })();
</script>
@endsection
