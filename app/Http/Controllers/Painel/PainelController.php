<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Store;
use App\Services\Leads\LeadDecisionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PainelController extends Controller
{
    /** Status ativos de uma sugestão de reabordagem (ainda não decidida). */
    private const OPEN_FOLLOWUP_STATUSES = ['candidate', 'draft_ready', 'approved'];

    private const TABS = ['ongoing', 'reconnect', 'won', 'lost', 'all'];

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'reconnect';
        $user = $request->user();

        $storeId = $user->isSeller() ? $user->store_id : $request->query('store');

        $leads = $this->scopeTab(Lead::query(), $tab)
            ->with(['customer', 'store', 'followups' => fn ($q) => $q->latest()])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderByDesc('last_contact_at')
            ->get();

        $counts = collect(self::TABS)->mapWithKeys(fn ($t) => [
            $t => $this->scopeTab(Lead::query(), $t)->when($storeId, fn ($q) => $q->where('store_id', $storeId))->count(),
        ]);

        return view('painel.index', [
            'leads' => $leads,
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
            'stores' => $user->isSeller() ? collect() : Store::orderBy('name')->get(),
            'selectedStore' => $storeId,
            'canDecide' => (bool) $user->can_decide,
        ]);
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

    public function approveFollowup(Request $request, LeadFollowup $followup, LeadDecisionService $decisions): RedirectResponse
    {
        $this->authorizeLead($request, $followup->lead);

        $decisions->approveFollowup($followup, $request->user());

        return back()->with('status', 'Sugestão aprovada.');
    }

    public function dismissFollowup(Request $request, LeadFollowup $followup, LeadDecisionService $decisions): RedirectResponse
    {
        $this->authorizeLead($request, $followup->lead);

        $decisions->dismissFollowup($followup);

        return back()->with('status', 'Sugestão descartada.');
    }

    public function markWon(Request $request, Lead $lead, LeadDecisionService $decisions): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $decisions->closeLeadWon($lead);

        return back()->with('status', 'Lead marcado como vendido.');
    }

    public function markLost(Request $request, Lead $lead, LeadDecisionService $decisions): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $decisions->closeLeadLost($lead, $request->string('reason')->trim()->value() ?: null);

        return back()->with('status', 'Lead marcado como perdido.');
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
