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
            // Chat ignorado (botão "Deletar") some do relatório pra sempre, mesmo nos dias em
            // que já tinha mensagem gravada antes de ser ignorado.
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

        $canDecide = (bool) $user->can_decide;

        // Conversa pode já ter virado lead (StalledConversationDetector) mesmo sem o
        // relatório ter sido gerado antes — busca pra poder mostrar os mesmos botões de
        // ação (Comprou/Perdeu/Deletar...) do painel direto aqui.
        $leadsByChat = Lead::query()
            ->whereIn('whatsapp_chat_id', $messages->pluck('whatsapp_chat_id')->unique())
            ->with(['customer', 'store', 'followups' => fn ($q) => $q->latest()])
            ->get()
            ->keyBy('whatsapp_chat_id');

        $conversations = $messages->groupBy('whatsapp_chat_id')->map(function ($group) use ($scorer, $leadsByChat) {
            $first = $group->first();
            $customerMessages = $group->where('direction', 'in')->values();
            $score = $scorer->scoreMessages($customerMessages);

            return [
                'chat' => $first->chat,
                'store' => $first->account->store,
                'messages' => $group->values(),
                'last_sent_at' => $group->max('sent_at'),
                'recebidas' => $group->where('direction', 'in')->count(),
                'enviadas' => $group->where('direction', 'out')->count(),
                'lead' => $leadsByChat->get($first->whatsapp_chat_id),
                'score' => $score,
                'priority_label' => $scorer->label($score),
                'priority_reason' => $scorer->reasonForMessages($customerMessages),
            ];
        });

        $conversations = $conversations->sortByDesc('score')->values();

        // Busca ao vivo (JS em report.blade.php): só troca a área de resultados, sem navegar
        // a página — mesmo padrão já usado no painel principal.
        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('painel.partials.report-results', [
                    'search' => $search, 'date' => $date, 'summary' => $summary,
                    'messages' => $messages, 'conversations' => $conversations,
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
            'canDecide' => $canDecide,
            'chartDefaultFrom' => $date->subDays(6)->toDateString(),
            'chartDefaultTo' => $date->toDateString(),
        ]);
    }

    /**
     * Gráfico de rosca "Ações no período" do Relatório do dia: conta, pelo intervalo de datas
     * escolhido (data início/fim), quantos leads entraram em cada desfecho. Cada categoria usa
     * a data da própria ação (won_at/lost_at/deleted_at), exceto "Em aberto" (sem ação ainda),
     * que usa a data de criação do lead — não tem outra data pra contar ali.
     */
    public function actionsSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $from = CarbonImmutable::parse($request->query('from'))->startOfDay();
        } catch (\Exception) {
            $from = CarbonImmutable::now()->subDays(6)->startOfDay();
        }

        try {
            $to = CarbonImmutable::parse($request->query('to'))->endOfDay();
        } catch (\Exception) {
            $to = CarbonImmutable::now()->endOfDay();
        }

        $storeId = $user->isSeller() ? $user->store_id : $request->query('store');
        $storeId = is_numeric($storeId) ? (int) $storeId : null;

        $base = Lead::withTrashed()->when($storeId, fn ($q) => $q->where('store_id', $storeId));

        $open = (clone $base)->whereNull('deleted_at')
            ->where('status', 'open')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $won = (clone $base)->whereNotNull('won_at')
            ->whereBetween('won_at', [$from, $to])
            ->count();

        $lost = (clone $base)->whereNotNull('lost_at')
            ->whereBetween('lost_at', [$from, $to])
            ->count();

        $deleted = (clone $base)->onlyTrashed()
            ->whereBetween('deleted_at', [$from, $to])
            ->count();

        return response()->json([
            'labels' => ['Em aberto', 'Comprou', 'Perdeu', 'Deletado/Ignorado'],
            'counts' => [$open, $won, $lost, $deleted],
        ]);
    }

    /**
     * Botão "Deletar" do Relatório do dia pra conversa que ainda não virou lead (ex.:
     * fornecedor com atendimento automático) — mesmo efeito do "Deletar" do painel
     * (App\Services\Leads\LeadDecisionService::deletePermanently), só que sem precisar de um
     * Lead já existir, por isso é uma rota própria em vez de PainelController::destroy().
     */
    public function ignoreChat(Request $request, WhatsappChat $chat): RedirectResponse|JsonResponse
    {
        $this->authorizeChat($request, $chat);

        $chat->update(['ignored' => true]);

        $message = 'Contato removido — marcado pra nunca mais virar lead.';

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true, 'message' => $message, 'chatId' => $chat->id]);
        }

        return back()->with('status', $message)->with('status_undo', [
            'label' => 'Desfazer',
            'url' => route('painel.chats.restore', $chat),
        ]);
    }

    /** Desfaz o "Deletar" de uma conversa sem lead: a flag "ignored" não apaga nada de verdade. */
    public function restoreChat(Request $request, WhatsappChat $chat): RedirectResponse|JsonResponse
    {
        $this->authorizeChat($request, $chat);

        $chat->update(['ignored' => false]);

        $message = 'Contato restaurado.';

        if ($request->wantsJson()) {
            return response()->json(['message' => $message]);
        }

        return back()->with('status', $message);
    }

    private function authorizeChat(Request $request, WhatsappChat $chat): void
    {
        $user = $request->user();

        if (! $user->can_decide) {
            abort(Response::HTTP_FORBIDDEN, 'Você não tem permissão para decidir sobre leads.');
        }

        if ($user->isSeller() && $chat->account->store_id !== $user->store_id) {
            abort(Response::HTTP_FORBIDDEN, 'Essa conversa não é da sua loja.');
        }
    }
}
