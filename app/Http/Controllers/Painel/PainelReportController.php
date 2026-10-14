<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Store;
use App\Models\WhatsappChat;
use App\Models\WhatsappMessage;
use App\Services\Leads\LeadPriorityScorer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PainelReportController extends Controller
{
    public function index(Request $request, LeadPriorityScorer $scorer): View|JsonResponse
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
            // Chat ignorado (botão "Ignorar"/"Deletar") some do relatório pra sempre, mesmo
            // nos dias em que já tinha mensagem gravada antes de ser ignorado.
            ->whereHas('chat', fn ($q) => $q->where('kind', 'individual')->where('ignored', false))
            // Buscando, o contato tem que aparecer independente do dia — "se tá no banco, tem
            // que aparecer" — então só restringe pelo dia selecionado quando não há busca.
            ->when($search === '', fn ($q) => $q->whereBetween('sent_at', [$date, $date->endOfDay()]))
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
        $canDecide = (bool) $user->can_decide;

        // Conversa pode já ter virado lead (StalledConversationDetector) mesmo sem o
        // relatório ter sido gerado antes — busca pra poder mostrar os mesmos botões de
        // ação (Comprou/Perdeu/Deletar...) do painel direto aqui.
        $leadsByChat = Lead::query()
            ->whereIn('whatsapp_chat_id', $messages->pluck('whatsapp_chat_id')->unique())
            ->with(['customer', 'store', 'followups' => fn ($q) => $q->latest()])
            ->get()
            ->keyBy('whatsapp_chat_id');

        $conversations = $messages->groupBy('whatsapp_chat_id')->map(function ($group) use ($scorer, $ranked, $leadsByChat) {
            $first = $group->first();
            $entry = [
                'chat' => $first->chat,
                'store' => $first->account->store,
                'messages' => $group->values(),
                'last_sent_at' => $group->max('sent_at'),
                'recebidas' => $group->where('direction', 'in')->count(),
                'enviadas' => $group->where('direction', 'out')->count(),
                'lead' => $leadsByChat->get($first->whatsapp_chat_id),
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

        // Busca ao vivo (JS em report.blade.php): só troca a área de resultados, sem navegar
        // a página — mesmo padrão já usado no painel principal.
        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('painel.partials.report-results', [
                    'search' => $search, 'date' => $date, 'summary' => $summary,
                    'messages' => $messages, 'conversations' => $conversations, 'ranked' => $ranked,
                    'canDecide' => $canDecide,
                ])->render(),
            ]);
        }

        return view('painel.report', [
            'date' => $date,
            'messages' => $messages,
            'conversations' => $conversations,
            'summary' => $summary,
            'stores' => $user->isSeller() ? collect() : Store::where('code', '!=', 'MIXCELL')->orderBy('name')->get(),
            'selectedStore' => $storeId,
            'search' => $search,
            'ranked' => $ranked,
            'canDecide' => $canDecide,
        ]);
    }

    /**
     * Ignora uma conversa direto do Relatório do dia, mesmo quando ela ainda não virou lead
     * (ex.: fornecedor com atendimento automático) — mesmo efeito do botão "Deletar" do
     * painel (App\Services\Leads\LeadDecisionService::deletePermanently), só que sem precisar
     * de um Lead já existir.
     */
    public function ignoreChat(Request $request, WhatsappChat $chat): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        if (! $user->can_decide) {
            abort(Response::HTTP_FORBIDDEN, 'Você não tem permissão para decidir sobre leads.');
        }

        if ($user->isSeller() && $chat->account->store_id !== $user->store_id) {
            abort(Response::HTTP_FORBIDDEN, 'Essa conversa não é da sua loja.');
        }

        $chat->update(['ignored' => true]);

        $message = 'Contato ignorado — nunca mais vira lead e some do relatório.';

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true, 'message' => $message]);
        }

        return back()->with('status', $message);
    }
}
