<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Store;
use App\Services\Leads\LeadDecisionService;
use App\Services\Leads\LeadPriorityScorer;
use App\Services\Market\SupplierPartsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PainelController extends Controller
{
    /** Status ativos de uma sugestão de reabordagem (ainda não decidida). */
    private const OPEN_FOLLOWUP_STATUSES = ['candidate', 'draft_ready', 'approved'];

    private const TABS = ['ongoing', 'reconnect', 'won', 'lost', 'all'];

    /** Valor do filtro ?priority= => rótulo que LeadPriorityScorer::label() devolve. */
    private const PRIORITY_LABELS = ['alta' => 'Alta', 'media' => 'Média', 'baixa' => 'Baixa'];

    public function index(Request $request, LeadPriorityScorer $scorer, SupplierPartsService $parts): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'reconnect';
        $user = $request->user();

        $storeId = $user->isSeller() ? $user->store_id : $request->query('store');
        // Vem da URL — pode ser lixo (edição manual, link quebrado). Sem isso, "store=abc" quebra a query no banco.
        $storeId = is_numeric($storeId) ? (int) $storeId : null;
        $search = trim((string) $request->query('search'));
        $priority = (string) $request->query('priority', '');

        if (! isset(self::PRIORITY_LABELS[$priority])) {
            $priority = '';
        }

        $leads = $this->scopeTab(Lead::query(), $tab)
            ->with(['customer', 'store', 'chat', 'followups' => fn ($q) => $q->latest()])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when($search !== '', fn ($q) => $this->scopeSearch($q, $search))
            ->orderByDesc('last_contact_at')
            ->get();

        // Filtro de prioridade (Alta/Média/Baixa) é calculado por regra, não dá pra fazer
        // em SQL — filtra em cima da coleção já carregada.
        if ($priority !== '') {
            $leads = $leads->filter(fn (Lead $lead) => $lead->status === 'open'
                && $scorer->label($scorer->score($lead)) === self::PRIORITY_LABELS[$priority])->values();
        }

        // Na aba de reconectar, quem tem mais sinal de interesse real (pergunta de
        // fechamento, orçamento já dado, engajamento) vem primeiro, não só o mais recente.
        if ($tab === 'reconnect') {
            $leads = $leads->sortByDesc(fn (Lead $lead) => $scorer->score($lead))->values();
        }

        $counts = collect(self::TABS)->mapWithKeys(fn ($t) => [
            $t => $this->scopeTab(Lead::query(), $t)
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->when($search !== '', fn ($q) => $this->scopeSearch($q, $search))
                ->count(),
        ]);

        return view('painel.index', [
            'leads' => $leads,
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
            'stores' => $user->isSeller() ? collect() : Store::where('code', '!=', 'MIXCELL')->orderBy('name')->get(),
            'selectedStore' => $storeId,
            'search' => $search,
            'priority' => $priority,
            'canDecide' => (bool) $user->can_decide,
            'partsByCategory' => $parts->summaryByCategory(),
        ]);
    }

    public function partsSearch(Request $request, SupplierPartsService $parts): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        return response()->json(['results' => $parts->search($term)->values()]);
    }

    /** @param  Builder<Lead>  $query */
    private function scopeTab($query, string $tab)
    {
        return match ($tab) {
            'ongoing' => $query->where('status', 'open')
                ->whereDoesntHave('followups', fn ($q) => $q->whereIn('status', self::OPEN_FOLLOWUP_STATUSES)),
            'reconnect' => $query->where('status', 'open')
                ->whereHas('followups', fn ($q) => $q->whereIn('status', self::OPEN_FOLLOWUP_STATUSES)),
            'won', 'lost' => $query->where('status', $tab),
            default => $query,
        };
    }

    /** @param  Builder<Lead>  $query */
    private function scopeSearch($query, string $term)
    {
        // ilike (não like): Postgres é case-sensitive por padrão em LIKE.
        return $query->where(fn (Builder $q) => $q
            ->whereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$term}%")
                ->orWhere('phone', 'ilike', "%{$term}%"))
            ->orWhere('product_interest', 'ilike', "%{$term}%"));
    }

    public function approveFollowup(Request $request, LeadFollowup $followup, LeadDecisionService $decisions): RedirectResponse|JsonResponse
    {
        $this->authorizeLead($request, $followup->lead);

        $decisions->approveFollowup($followup, $request->user());

        $storeCode = $followup->lead->store->code;

        return $this->respond($request, $followup->lead, "Sugestão aprovada. Copie o link do WhatsApp e cole na janela do Chrome da loja {$storeCode}.");
    }

    public function dismissFollowup(Request $request, LeadFollowup $followup, LeadDecisionService $decisions): RedirectResponse|JsonResponse
    {
        $this->authorizeLead($request, $followup->lead);

        $decisions->dismissFollowup($followup);

        return $this->respond($request, $followup->lead, 'Lead separado — volta pra "Em andamento".');
    }

    public function unapproveFollowup(Request $request, LeadFollowup $followup, LeadDecisionService $decisions): RedirectResponse|JsonResponse
    {
        $this->authorizeLead($request, $followup->lead);

        $decisions->unapproveFollowup($followup);

        return $this->respond($request, $followup->lead, 'Aprovação desfeita.');
    }

    public function markWon(Request $request, Lead $lead, LeadDecisionService $decisions): RedirectResponse|JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $decisions->closeLeadWon($lead);

        return $this->respond($request, $lead, 'Lead marcado como vendido.');
    }

    public function markLost(Request $request, Lead $lead, LeadDecisionService $decisions): RedirectResponse|JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $decisions->closeLeadLost($lead, $request->string('reason')->trim()->value() ?: null);

        return $this->respond($request, $lead, 'Lead marcado como perdido.');
    }

    public function destroy(Request $request, Lead $lead, LeadDecisionService $decisions): RedirectResponse|JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $decisions->deletePermanently($lead);

        $message = 'Contato removido — marcado pra nunca mais virar lead.';

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true, 'message' => $message]);
        }

        return back()->with('status', $message);
    }

    private function respond(Request $request, Lead $lead, string $message): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'row' => view('painel.partials.lead-row', [
                    'lead' => $lead->fresh(['customer', 'store', 'chat', 'followups' => fn ($q) => $q->latest()]),
                    'canDecide' => (bool) $request->user()->can_decide,
                ])->render(),
            ]);
        }

        return back()->with('status', $message);
    }

    private function authorizeLead(Request $request, Lead $lead): void
    {
        $user = $request->user();

        if (! $user->can_decide) {
            abort(Response::HTTP_FORBIDDEN, 'Você não tem permissão para decidir sobre leads.');
        }

        if ($user->isSeller() && $lead->store_id !== $user->store_id) {
            abort(Response::HTTP_FORBIDDEN, 'Esse lead não é da sua loja.');
        }
    }
}
