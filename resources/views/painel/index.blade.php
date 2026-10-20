@extends('layouts.app')

@section('title', 'Leads')

@php
    $tabLabel = [
        'reconnect' => 'Reconectar',
        'won' => 'Pós-venda',
        'lost' => 'Desistiu',
        'ongoing' => 'Em andamento',
    ];
@endphp

@section('header-actions')
    <a href="{{ route('painel.report', ['store' => $selectedStore]) }}"
        class="whitespace-nowrap rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
        Relatório do dia
    </a>

    <a href="{{ route('painel.parts.index') }}"
        class="whitespace-nowrap rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
        🔧 Consultar preço/produtos
    </a>

    @if ($stores->isNotEmpty())
        <form method="GET" class="text-sm">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="hidden" name="search" value="{{ $search }}">
            <input type="hidden" name="priority" value="{{ $priority }}">
            <input type="hidden" name="category" value="{{ $category }}">
            <select name="store" onchange="this.form.submit()"
                class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                <option value="">Todas as lojas</option>
                @foreach ($stores as $store)
                    <option value="{{ $store->id }}" @selected((string) $selectedStore === (string) $store->id)>{{ $store->code }}</option>
                @endforeach
            </select>
        </form>
    @endif
@endsection

@section('content')
<div class="mb-6 flex flex-nowrap items-center justify-between gap-4">
    <nav class="flex min-w-0 flex-nowrap gap-1 overflow-x-auto rounded-lg bg-slate-200/60 p-1 text-sm dark:bg-slate-800/60">
        @foreach ($tabs as $t)
            <a href="{{ route('painel.index', ['tab' => $t, 'store' => $selectedStore, 'search' => $search, 'priority' => $priority, 'category' => $category]) }}"
                class="whitespace-nowrap rounded-md px-3 py-1.5 font-medium {{ $tab === $t ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white' : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100' }}">
                {{ $tabLabel[$t] }} <span class="text-slate-400 dark:text-slate-500">({{ $counts[$t] }})</span>
            </a>
        @endforeach
    </nav>

    <form method="GET" class="flex-shrink-0 text-sm">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="hidden" name="store" value="{{ $selectedStore }}">
        <input type="hidden" name="priority" value="{{ $priority }}">
        <input type="hidden" name="category" value="{{ $category }}">
        <input type="search" name="search" id="leadSearchInput" value="{{ $search }}" placeholder="Nome, telefone ou produto/orçamento"
            class="w-64 rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500" autocomplete="off">
    </form>
</div>

<div class="mb-4 flex flex-wrap items-center gap-4">
    <form method="GET" class="flex items-center gap-2 text-sm">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="hidden" name="store" value="{{ $selectedStore }}">
        <input type="hidden" name="search" value="{{ $search }}">
        <input type="hidden" name="category" value="{{ $category }}">
        <label for="priorityFilter" class="font-medium text-slate-600 dark:text-slate-400">Prioridade:</label>
        <select name="priority" id="priorityFilter" onchange="this.form.submit()"
            class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Todas as prioridades</option>
            <option value="alta" @selected($priority === 'alta')>🔥 Alta</option>
            <option value="media" @selected($priority === 'media')>🟡 Média</option>
            <option value="baixa" @selected($priority === 'baixa')>⚪ Baixa</option>
        </select>
    </form>

    <form method="GET" class="flex items-center gap-2 text-sm">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="hidden" name="store" value="{{ $selectedStore }}">
        <input type="hidden" name="search" value="{{ $search }}">
        <input type="hidden" name="priority" value="{{ $priority }}">
        <label for="categoryFilter" class="font-medium text-slate-600 dark:text-slate-400">Produto:</label>
        <select name="category" id="categoryFilter" onchange="this.form.submit()"
            class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Todos os produtos</option>
            <option value="celular" @selected($category === 'celular')>📱 Celular</option>
            <option value="peca" @selected($category === 'peca')>🔧 Peças</option>
        </select>
    </form>
</div>

<div id="leadsResults">
    @include('painel.partials.leads-results', ['leads' => $leads, 'canDecide' => $canDecide])
</div>

<div class="mt-6">
    @include('painel.partials.business-rules')
</div>

<script>
    let flashHideTimer = null;

    (function () {
        const input = document.getElementById('leadSearchInput');
        const results = document.getElementById('leadsResults');

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
            // Não usa input.form.action aqui — sem um "action" explícito no <form>, ele
            // resolve pra URL atual JÁ COM a query string antiga, o que duplicaria os
            // parâmetros (ex.: "...?tab=x?tab=y"). window.location.pathname é só "/painel".
            const url = window.location.pathname + '?' + params.toString();

            fetch(url, { headers: { Accept: 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    results.innerHTML = data.html;
                    bindCopyButtons(results);
                    bindTooltips(results);
                    results.querySelectorAll('form.js-row-action').forEach(bindRowAction);
                    history.replaceState(null, '', url);
                });
        }
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

    function showRowToast(row, message, undo) {
        const colCount = row.children.length;
        const toastRow = document.createElement('tr');
        toastRow.className = 'js-row-toast transition-opacity duration-500';
        const td = document.createElement('td');
        td.colSpan = colCount;
        td.className = 'flex items-center justify-between gap-3 bg-emerald-50 px-4 py-2 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200';

        const span = document.createElement('span');
        span.textContent = message;
        td.appendChild(span);

        let hideTimer;

        if (undo) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = undo.label;
            button.className = 'whitespace-nowrap rounded-full border border-emerald-400 px-2.5 py-1 font-semibold hover:bg-emerald-100 dark:border-emerald-700 dark:hover:bg-emerald-900';
            button.addEventListener('click', function () {
                clearTimeout(hideTimer);

                const body = new FormData();
                body.append('_token', document.querySelector('meta[name="csrf-token"]').content);

                fetch(undo.url, { method: 'POST', headers: { Accept: 'application/json' }, body: body })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data.row) {
                            toastRow.remove();

                            return;
                        }

                        toastRow.outerHTML = data.row;

                        const restoredRow = document.querySelector('[data-lead-row="' + undo.leadId + '"]');
                        if (restoredRow) {
                            bindCopyButtons(restoredRow);
                            bindTooltips(restoredRow);
                            restoredRow.querySelectorAll('form.js-row-action').forEach(bindRowAction);
                        }
                    });
            });
            td.appendChild(button);
        }

        toastRow.appendChild(td);
        row.parentNode.insertBefore(toastRow, row);
        row.remove();

        hideTimer = setTimeout(function () {
            toastRow.style.opacity = '0';
            setTimeout(function () {
                toastRow.remove();
            }, 500);
        }, undo ? 20000 : 5000);
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
                        showRowToast(row, data.message || 'Contato removido.', data.leadId ? {
                            label: 'Desfazer',
                            url: '/painel/leads/' + data.leadId + '/restore',
                            leadId: data.leadId,
                        } : null);

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
</script>
@endsection
