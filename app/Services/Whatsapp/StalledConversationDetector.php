<?php

namespace App\Services\Whatsapp;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\WhatsappChat;
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
        $hasCustomerMessage = $chat->messages()->where('direction', 'in')->exists();

        if ($lastMessage === null || ! $hasCustomerMessage) {
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

        $followupCreated = $this->maybeCreateFollowup($lead, $reason, $config);

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
    private function maybeCreateFollowup(Lead $lead, string $reason, array $config): bool
    {
        $openExists = $lead->followups()
            ->whereIn('status', ['candidate', 'draft_ready', 'approved'])
            ->exists();

        if ($openExists) {
            return false;
        }

        $inCooldown = $lead->followups()
            ->whereIn('status', ['dismissed', 'sent'])
            ->where('updated_at', '>=', CarbonImmutable::now()->subDays($config['cooldown_days']))
            ->exists();

        if ($inCooldown) {
            return false;
        }

        LeadFollowup::create([
            'lead_id' => $lead->id,
            'type' => 'recover',
            'reason' => $reason,
            'status' => 'candidate',
            'suggested_by' => 'rules',
        ]);

        return true;
    }
}
