<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\WhatsappMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Clientes que falaram de boleto: lista fixa (o histórico inteiro fica sempre aqui) e o aviso
 * (pop-up) que checa se chegou mensagem nova. Só olha mensagens RECEBIDAS, de conversa
 * individual e não ignorada. Não mexe na busca de texto do Painel/Relatório.
 */
class BoletoController extends Controller
{
    private const TERM = 'boleto';

    public function index(Request $request): View
    {
        $messages = $this->baseQuery($request)
            ->with(['chat', 'account.store'])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get();

        $clients = $messages->groupBy('whatsapp_chat_id')->map(function ($group) {
            $last = $group->first();

            return [
                'chat' => $last->chat,
                'store' => $last->account->store,
                'mentions' => $group->count(),
                'last_message' => $last,
                'max_id' => $group->max('id'),
            ];
        })->sortByDesc(fn (array $c) => $c['last_message']->sent_at)->values();

        return view('painel.boletos', [
            'clients' => $clients,
            'latestId' => (int) $messages->max('id'),
        ]);
    }

    /** Usado pelo pop-up: quantas mensagens/clientes novos de boleto desde o último id visto. */
    public function check(Request $request): JsonResponse
    {
        $after = max(0, (int) $request->query('after', 0));

        $base = $this->baseQuery($request);
        $latestId = (int) (clone $base)->max('id');

        // Primeira vez do navegador (sem id guardado): só devolve a base, sem avisar do passado.
        if (! $request->has('after')) {
            return response()->json(['count' => 0, 'clients' => 0, 'latest_id' => $latestId]);
        }

        $new = (clone $base)->where('id', '>', $after);

        return response()->json([
            'count' => (clone $new)->count(),
            'clients' => (clone $new)->distinct()->count('whatsapp_chat_id'),
            'latest_id' => $latestId,
        ]);
    }

    /** @return Builder<WhatsappMessage> */
    private function baseQuery(Request $request): Builder
    {
        $user = $request->user();

        return WhatsappMessage::query()
            ->where('direction', 'in')
            ->whereLike('body', '%'.self::TERM.'%')
            ->whereHas('chat', fn ($q) => $q->where('kind', 'individual')->where('ignored', false))
            ->when($user->isSeller(), fn ($q) => $q
                ->whereHas('account', fn ($a) => $a->where('store_id', $user->store_id)));
    }
}
