<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Store;
use App\Services\Leads\LeadDecisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PainelController extends Controller
{
    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['open', 'won', 'all'], true) ? $request->query('tab') : 'open';
        $user = $request->user();

        $storeId = $user->isSeller() ? $user->store_id : $request->query('store');

        $leads = Lead::query()
            ->with(['customer', 'store', 'followups' => fn ($q) => $q->latest()])
            ->when($tab !== 'all', fn ($q) => $q->where('status', $tab))
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderByDesc('last_contact_at')
            ->get();

        return view('painel.index', [
            'leads' => $leads,
            'tab' => $tab,
            'stores' => $user->isSeller() ? collect() : Store::orderBy('name')->get(),
            'selectedStore' => $storeId,
        ]);
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

        if ($user->isSeller() && $lead->store_id !== $user->store_id) {
            abort(Response::HTTP_FORBIDDEN, 'Esse lead não é da sua loja.');
        }
    }
}
