<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\WhatsappMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PainelReportController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $date = CarbonImmutable::parse($request->query('date', CarbonImmutable::now()->toDateString()))->startOfDay();
        $storeId = $user->isSeller() ? $user->store_id : $request->query('store');

        $messages = WhatsappMessage::query()
            ->whereHas('chat', fn ($q) => $q->where('kind', 'individual'))
            ->whereBetween('sent_at', [$date, $date->endOfDay()])
            ->with(['chat', 'account.store'])
            ->when($storeId, fn ($q) => $q->whereHas('account', fn ($a) => $a->where('store_id', $storeId)))
            ->orderBy('sent_at')
            ->get();

        $byStore = $messages->groupBy(fn (WhatsappMessage $m) => $m->account->store->code);

        $summary = $byStore->map(fn ($group) => [
            'recebidas' => $group->where('direction', 'in')->count(),
            'enviadas' => $group->where('direction', 'out')->count(),
        ])->sortKeys();

        return view('painel.report', [
            'date' => $date,
            'messages' => $messages,
            'summary' => $summary,
            'stores' => $user->isSeller() ? collect() : Store::orderBy('name')->get(),
            'selectedStore' => $storeId,
        ]);
    }
}
