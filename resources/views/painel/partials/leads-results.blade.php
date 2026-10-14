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
                    <th class="px-4 py-1.5">Data</th>
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
