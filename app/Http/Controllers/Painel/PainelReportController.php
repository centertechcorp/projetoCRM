<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\WhatsappMessage;
use App\Services\Leads\LeadPriorityScorer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PainelReportController extends Controller
{
    public function index(Request $request, LeadPriorityScorer $scorer): View
    {
        $user = $request->user();

        try {
            $date = CarbonImmutable::parse($request->query('date', CarbonImmutable::now()->toDateString()))->startOfDay();
        } catch (\Exception) {
            // Data inválida na URL (edição manual, link quebrado etc.) — cai pra hoje em vez de quebrar a página.
            $date = CarbonImmutable::now()->startOfDay();
        }
        $storeId = $user->isSeller() ? $user->store_id : $request->query('store');
        $storeId = is_numeric($storeId) ? (int) $storeId : null;
        $search = trim((string) $request->query('search'));

        $messages = WhatsappMessage::query()
            ->whereHas('chat', fn ($q) => $q->where('kind', 'individual'))
            ->whereBetween('sent_at', [$date, $date->endOfDay()])
            ->with(['chat', 'account.store'])
            ->when($storeId, fn ($q) => $q->whereHas('account', fn ($a) => $a->where('store_id', $storeId)))
            ->when($search !== '', fn ($q) => $q->whereHas('chat', fn (Builder $c) => $c
                ->where('display_name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")))
            ->orderBy('sent_at')
            ->get();

        $byStore = $messages->groupBy(fn (WhatsappMessage $m) => $m->account->store->code);

        $summary = $byStore->map(fn ($group) => [
            'recebidas' => $group->where('direction', 'in')->count(),
            'enviadas' => $group->where('direction', 'out')->count(),
        ])->sortKeys();

        $ranked = $request->boolean('rank');

        $conversations = $messages->groupBy('whatsapp_chat_id')->map(function ($group) use ($scorer, $ranked) {
            $first = $group->first();
            $entry = [
                'chat' => $first->chat,
                'store' => $first->account->store,
                'messages' => $group->values(),
                'last_sent_at' => $group->max('sent_at'),
                'recebidas' => $group->where('direction', 'in')->count(),
                'enviadas' => $group->where('direction', 'out')->count(),
            ];

            if ($ranked) {
                $customerMessages = $group->where('direction', 'in')->values();
                $entry['score'] = $scorer->scoreMessages($customerMessages);
                $entry['priority_label'] = $scorer->label($entry['score']);
                $entry['priority_reason'] = $scorer->reasonForMessages($customerMessages);
            }

            return $entry;
        });

        $conversations = $ranked
            ? $conversations->sortByDesc('score')->values()
            : $conversations->sortByDesc('last_sent_at')->values();

        return view('painel.report', [
            'date' => $date,
            'messages' => $messages,
            'conversations' => $conversations,
            'summary' => $summary,
            'stores' => $user->isSeller() ? collect() : Store::where('code', '!=', 'MIXCELL')->orderBy('name')->get(),
            'selectedStore' => $storeId,
            'search' => $search,
            'ranked' => $ranked,
        ]);
    }
}
