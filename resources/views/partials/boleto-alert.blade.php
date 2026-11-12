{{-- Pop-up "Novos clientes de boleto": consulta a cada 60 s e guarda o último id visto neste navegador. --}}
<div id="boletoAlert" role="alert"
    class="fixed bottom-4 right-4 z-50 hidden w-[calc(100%-2rem)] max-w-sm rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 shadow-lg dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="font-semibold">🧾 Novos clientes de boleto</p>
            <p id="boletoAlertText" class="mt-1"></p>
            <a id="boletoAlertLink" href="{{ route('painel.boletos') }}"
                class="mt-2 inline-block font-semibold underline underline-offset-2">Ver clientes de boleto</a>
        </div>
        <button type="button" id="boletoAlertClose" aria-label="Fechar aviso"
            class="rounded p-1 text-amber-700 hover:bg-amber-100 dark:text-amber-200 dark:hover:bg-amber-900">&times;</button>
    </div>
</div>

<script>
    (function () {
        const url = @json(route('painel.boletos.check'));
        const box = document.getElementById('boletoAlert');
        const text = document.getElementById('boletoAlertText');
        let pendingLatest = 0;

        function getSeen() {
            try {
                const v = localStorage.getItem('boleto_seen_id');
                return v === null ? null : (parseInt(v, 10) || 0);
            } catch (e) { return null; }
        }

        function setSeen(id) {
            try { localStorage.setItem('boleto_seen_id', String(id)); } catch (e) {}
        }

        function dismiss() {
            if (pendingLatest > 0) setSeen(pendingLatest);
            box.classList.add('hidden');
        }

        document.getElementById('boletoAlertClose')?.addEventListener('click', dismiss);
        // Clicar no link também conta como visto (a própria página grava o id ao abrir).
        document.getElementById('boletoAlertLink')?.addEventListener('click', function () {
            if (pendingLatest > 0) setSeen(pendingLatest);
        });

        async function check() {
            try {
                const seen = getSeen();
                const res = await fetch(seen === null ? url : url + '?after=' + seen, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!res.ok) return;
                const data = await res.json();

                // Primeiro acesso deste navegador: só marca a base, sem avisar do passado.
                if (seen === null) { setSeen(data.latest_id); return; }

                if (data.count > 0) {
                    pendingLatest = data.latest_id;
                    text.textContent = data.clients + (data.clients === 1 ? ' cliente falou' : ' clientes falaram')
                        + ' de boleto (' + data.count + (data.count === 1 ? ' mensagem nova).' : ' mensagens novas).');
                    box.classList.remove('hidden');
                }
            } catch (e) { /* sem rede ou erro: tenta de novo no próximo ciclo */ }
        }

        check();
        setInterval(check, 60000);
    })();
</script>
