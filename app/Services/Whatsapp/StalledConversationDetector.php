<?php

namespace App\Services\Whatsapp;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\WhatsappChat;
use App\Services\Leads\FollowupGate;
use Carbon\CarbonImmutable;

/**
 * Transforma conversas de WhatsApp paradas em leads a reabordar (App\Models\Lead +
 * LeadFollowup), sem enviar nem gravar nada além do próprio registro do lead: quem
 * aprova a reabordagem é sempre uma pessoa, pelo painel.
 */
class StalledConversationDetector
{
    // O cliente escreveu por último e ainda não foi respondido.
    public const REASON_CUSTOMER_UNANSWERED = 'customer_unanswered';

    // Nós (ou o bot) respondemos por último e o cliente não voltou a escrever.
    public const REASON_CUSTOMER_SILENT = 'customer_silent';

    public function __construct(private readonly FollowupGate $gate) {}

    /** @return array{leads_created: int, followups_created: int, chats_skipped: int} */
    public function detect(?int $storeId = null): array
    {
        $config = config('whatsapp.followup');
        $now = CarbonImmutable::now();
        $idleBefore = $now->subHours($config['min_idle_hours']);
        $notOlderThan = $now->subDays($config['max_age_days']);

        $chats = WhatsappChat::query()
            ->where('kind', 'individual')
            ->whereNotNull('last_message_at')
            ->whereBetween('last_message_at', [$notOlderThan, $idleBefore])
            ->when($storeId !== null, fn ($q) => $q->whereHas('account', fn ($a) => $a->where('store_id', $storeId)))
            ->with('account')
            ->get();

        $leadsCreated = 0;
        $followupsCreated = 0;
        $skipped = 0;

        foreach ($chats as $chat) {
            $result = $this->processChat($chat, $config);

            $leadsCreated += $result['lead_created'] ? 1 : 0;
            $followupsCreated += $result['followup_created'] ? 1 : 0;
            $skipped += $result['followup_created'] ? 0 : 1;
        }

        return ['leads_created' => $leadsCreated, 'followups_created' => $followupsCreated, 'chats_skipped' => $skipped];
    }

    /** @param  array<string, mixed>  $config */
    private function processChat(WhatsappChat $chat, array $config): array
    {
        $lastMessage = $chat->messages()->orderByDesc('sent_at')->first();
        $lastCustomerMessage = $chat->messages()->where('direction', 'in')->orderByDesc('sent_at')->first();

        if ($lastMessage === null || $lastCustomerMessage === null) {
            return ['lead_created' => false, 'followup_created' => false];
        }

        $reason = $lastMessage->direction === 'in'
            ? self::REASON_CUSTOMER_UNANSWERED
            : self::REASON_CUSTOMER_SILENT;

        $customer = $this->findOrCreateCustomer($chat);

        if ($customer === null) {
            return ['lead_created' => false, 'followup_created' => false];
        }

        [$lead, $leadCreated] = $this->findOrCreateLead($chat, $customer);

        $lead->last_contact_at = $chat->last_message_at;
        $lead->next_contact_at ??= CarbonImmutable::now();
        $lead->whatsapp_chat_id = $chat->id;
        $lead->save();

        $followupCreated = $this->maybeCreateFollowup($lead, $reason, $lastCustomerMessage->body, $config);

        return ['lead_created' => $leadCreated, 'followup_created' => $followupCreated];
    }

    private function findOrCreateCustomer(WhatsappChat $chat): ?Customer
    {
        $phone = PhoneNormalizer::normalize($chat->phone ?? $chat->chat_key);

        if ($phone === null) {
            return null;
        }

        $customer = Customer::withTrashed()->firstOrCreate(
            ['phone' => $phone],
            ['name' => $chat->display_name],
        );

        if ($customer->trashed()) {
            $customer->restore();
        }

        if ($customer->name === null && $chat->display_name !== null) {
            $customer->update(['name' => $chat->display_name]);
        }

        return $customer;
    }

    /** @return array{0: Lead, 1: bool} */
    private function findOrCreateLead(WhatsappChat $chat, Customer $customer): array
    {
        $storeId = $chat->account->store_id;

        $lead = Lead::query()
            ->where('customer_id', $customer->id)
            ->where('store_id', $storeId)
            ->where('status', 'open')
            ->first();

        if ($lead !== null) {
            return [$lead, false];
        }

        $lead = Lead::create([
            'customer_id' => $customer->id,
            'store_id' => $storeId,
            'whatsapp_chat_id' => $chat->id,
            'source' => 'whatsapp',
            'status' => 'open',
        ]);

        return [$lead, true];
    }

    /** @param  array<string, mixed>  $config */
    private function maybeCreateFollowup(Lead $lead, string $reason, string $lastCustomerMessageBody, array $config): bool
    {
        // O cliente só se despediu ("obrigado", "valeu"); a conversa terminou bem,
        // não precisa de reabordagem — mas o contato acima já atualizou o lead.
        if (ClosingPhraseMatcher::isClosing($lastCustomerMessageBody, $config['closing_phrases'])) {
            return false;
        }

        return $this->gate->createIfAllowed($lead, 'recover', $reason, $config['cooldown_days'], $config['max_attempts']);
    }
}
