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
@endphp

@section('content')
@if ($partsByCategory)
    <div class="mb-4 rounded-xl border border-slate-200 bg-white p-3 text-sm shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
            <span class="font-medium text-slate-900 dark:text-slate-100">🔧 Peças (Mix Atacado)</span>
            <input type="search" id="partsSearchInput" placeholder="Buscar peça (ex: iphone 13)" autocomplete="off"
                class="w-64 rounded-lg border border-slate-300 px-3 py-1 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500">
        </div>

        <div id="partsSearchResults" class="hidden mb-2 max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-xs dark:divide-slate-800 dark:border-slate-700"></div>

        <details id="partsSummaryTable">
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
@endif

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <nav class="flex flex-wrap gap-1 rounded-lg bg-slate-200/60 p-1 text-sm dark:bg-slate-800/60">
        @foreach ($tabs as $t)
            <a href="{{ route('painel.index', ['tab' => $t, 'store' => $selectedStore, 'search' => $search, 'priority' => $priority]) }}"
                class="rounded-md px-3 py-1.5 font-medium {{ $tab === $t ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white' : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100' }}">
                {{ $tabLabel[$t] }} <span class="text-slate-400 dark:text-slate-500">({{ $counts[$t] }})</span>
            </a>
        @endforeach
    </nav>

    <div class="flex items-center gap-2">
        <form method="GET" class="text-sm">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="hidden" name="store" value="{{ $selectedStore }}">
            <input type="hidden" name="priority" value="{{ $priority }}">
            <input type="search" name="search" id="leadSearchInput" value="{{ $search }}" placeholder="Nome, telefone ou produto/orçamento"
                class="w-64 rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500" autocomplete="off">
        </form>

        <a href="{{ route('painel.report', ['store' => $selectedStore]) }}"
            class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
            Relatório do dia
        </a>

        @if ($stores->isNotEmpty())
            <form method="GET" class="text-sm">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <input type="hidden" name="search" value="{{ $search }}">
                <input type="hidden" name="priority" value="{{ $priority }}">
                <select name="store" onchange="this.form.submit()"
                    class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                    <option value="">Todas as lojas</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}" @selected((string) $selectedStore === (string) $store->id)>{{ $store->code }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>
</div>

<div class="mb-4 flex items-center gap-2">
    <form method="GET" class="flex items-center gap-2 text-sm">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="hidden" name="store" value="{{ $selectedStore }}">
        <input type="hidden" name="search" value="{{ $search }}">
        <label for="priorityFilter" class="font-medium text-slate-600 dark:text-slate-400">Prioridade:</label>
        <select name="priority" id="priorityFilter" onchange="this.form.submit()"
            class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Todas as prioridades</option>
            <option value="alta" @selected($priority === 'alta')>🔥 Alta</option>
            <option value="media" @selected($priority === 'media')>🟡 Média</option>
            <option value="baixa" @selected($priority === 'baixa')>⚪ Baixa</option>
        </select>
    </form>
</div>

@if ($leads->isEmpty())
    <p class="rounded-lg border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
        Nenhum lead nesta aba ainda.
    </p>
@else
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                <tr>
                    <th class="px-4 py-1.5">Cliente</th>
                    <th class="px-4 py-1.5">Loja</th>
                    <th class="px-4 py-1.5">Produto / Orçamento</th>
                    <th class="px-4 py-1.5">Origem · Atendente</th>
                    <th class="px-4 py-1.5">Próximo contato</th>
                    <th class="px-4 py-1.5">Sugestão de reabordagem</th>
                    <th class="px-4 py-1.5">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($leads as $lead)
                    @include('painel.partials.lead-row', ['lead' => $lead, 'canDecide' => $canDecide])
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="mt-6">
    @include('painel.partials.business-rules')
</div>

<script>
    let flashHideTimer = null;

    (function () {
        const input = document.getElementById('leadSearchInput');

        if (!input) {
            return;
        }

        input.addEventListener('input', function () {
            const term = input.value.trim().toLowerCase();

            document.querySelectorAll('[data-lead-search]').forEach(function (row) {
                row.style.display = row.dataset.leadSearch.includes(term) ? '' : 'none';
            });
        });
    })();

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

    let tooltipEl = null;
    let tooltipTimer = null;

    function bindTooltips(scope) {
        scope.querySelectorAll('[data-tooltip]').forEach(function (el) {
            el.addEventListener('mouseenter', function () {
                clearTimeout(tooltipTimer);
                tooltipTimer = setTimeout(function () {
                    if (!tooltipEl) {
                        tooltipEl = document.createElement('div');
                        tooltipEl.className = 'pointer-events-none fixed z-50 max-w-xs rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs text-white shadow-lg dark:bg-slate-100 dark:text-slate-900';
                        document.body.appendChild(tooltipEl);
                    }

                    tooltipEl.textContent = el.dataset.tooltip;
                    tooltipEl.style.visibility = 'hidden';
                    tooltipEl.style.display = 'block';

                    const rect = el.getBoundingClientRect();
                    const tipRect = tooltipEl.getBoundingClientRect();
                    let top = rect.top - tipRect.height - 8;
                    if (top < 4) {
                        top = rect.bottom + 8;
                    }
                    let left = rect.left + (rect.width / 2) - (tipRect.width / 2);
                    left = Math.max(4, Math.min(left, window.innerWidth - tipRect.width - 4));

                    tooltipEl.style.top = top + 'px';
                    tooltipEl.style.left = left + 'px';
                    tooltipEl.style.visibility = 'visible';
                }, 3000);
            });

            el.addEventListener('mouseleave', function () {
                clearTimeout(tooltipTimer);
                if (tooltipEl) {
                    tooltipEl.style.display = 'none';
                }
            });
        });
    }

    function showRowToast(row, message) {
        const colCount = row.children.length;
        const toastRow = document.createElement('tr');
        toastRow.className = 'js-row-toast transition-opacity duration-500';
        const td = document.createElement('td');
        td.colSpan = colCount;
        td.className = 'bg-emerald-50 px-4 py-2 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200';
        td.textContent = message;
        toastRow.appendChild(td);
        row.parentNode.insertBefore(toastRow, row);

        setTimeout(function () {
            toastRow.style.opacity = '0';
            setTimeout(function () {
                toastRow.remove();
            }, 500);
        }, 5000);
    }

    function bindRowAction(form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (form.classList.contains('js-row-delete') && !confirm('Tem certeza que deseja deletar esse contato?')) {
                return;
            }

            const row = form.closest('[data-lead-row]');
            const leadId = row ? row.dataset.leadRow : null;

            fetch(form.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: new FormData(form),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!row) {
                        return;
                    }

                    if (data.deleted) {
                        row.style.transition = 'opacity 0.3s';
                        row.style.opacity = '0';
                        setTimeout(function () { row.remove(); }, 300);

                        return;
                    }

                    if (!data.row) {
                        return;
                    }

                    row.outerHTML = data.row;

                    const newRow = document.querySelector('[data-lead-row="' + leadId + '"]');
                    if (newRow) {
                        bindCopyButtons(newRow);
                        bindTooltips(newRow);
                        newRow.querySelectorAll('form.js-row-action').forEach(bindRowAction);

                        if (data.message) {
                            showRowToast(newRow, data.message);
                        }
                    }
                });
        });
    }

    bindCopyButtons(document);
    bindTooltips(document);
    document.querySelectorAll('form.js-row-action').forEach(bindRowAction);

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
