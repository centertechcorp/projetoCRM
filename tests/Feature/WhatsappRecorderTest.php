<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappMedia;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\IncomingMedia;
use App\Services\Whatsapp\IncomingMessage;
use App\Services\Whatsapp\MessageEndpoint;
use App\Services\Whatsapp\MessageRecorder;
use App\Services\Whatsapp\MessageStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappRecorderTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-da-conta-center';

    private WhatsappAccount $account;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/whatsapp-recorder-'.bin2hex(random_bytes(4));
        $this->account = $this->makeAccount('CENTER', self::TOKEN);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);

        parent::tearDown();
    }

    public function test_individual_message_creates_chat_message_and_event(): void
    {
        $status = $this->recorder()->record($this->account, $this->message(), 'web_extension', ['id' => 'a1']);

        $this->assertSame('stored', $status);

        $chat = WhatsappChat::sole();
        $this->assertSame('5511999998888', $chat->chat_key);
        $this->assertSame('individual', $chat->kind);
        $this->assertSame('5511999998888', $chat->phone);
        $this->assertSame('Maria', $chat->display_name);
        $this->assertNotNull($chat->last_message_at);

        $message = WhatsappMessage::sole();
        $this->assertSame('in', $message->direction);
        $this->assertSame('oi', $message->body);
        $this->assertSame('web_extension', $message->source);
        $this->assertSame(['id' => 'a1'], $message->payload);
        $this->assertSame($this->account->id, $message->whatsapp_account_id);

        $event = Event::sole();
        $this->assertSame(MessageRecorder::EVENT_MESSAGE, $event->type);
        $this->assertSame($this->account->store_id, $event->store_id);
        $this->assertSame($message->id, $event->subject_id);
    }

    public function test_resending_the_same_message_is_a_duplicate(): void
    {
        $this->recorder()->record($this->account, $this->message(), 'web_extension');

        $status = $this->recorder()->record($this->account, $this->message(), 'web_extension');

        $this->assertSame('duplicate', $status);
        $this->assertSame(1, WhatsappMessage::count());
        $this->assertSame(1, Event::count());
    }

    public function test_group_message_keeps_who_spoke_and_does_not_rename_the_group(): void
    {
        $this->recorder()->record($this->account, $this->message(
            id: 'g1',
            chat: '5511963381709-1561816551@g.us',
            name: 'Valio',
            senderJid: '113362037915713@lid',
        ), 'web_extension');

        $chat = WhatsappChat::sole();
        $this->assertSame('grupo_5511963381709_1561816551', $chat->chat_key);
        $this->assertSame('group', $chat->kind);
        $this->assertNull($chat->phone);
        $this->assertNull($chat->display_name);
        $this->assertSame('113362037915713@lid', WhatsappMessage::sole()->sender_jid);
    }

    public function test_lid_chat_has_no_phone(): void
    {
        $this->recorder()->record($this->account, $this->message(chat: '113404366831863@lid'), 'web_extension');

        $this->assertNull(WhatsappChat::sole()->phone);
    }

    public function test_own_messages_do_not_rename_the_contact(): void
    {
        $recorder = $this->recorder();
        $recorder->record($this->account, $this->message(id: 'a1', name: 'Maria'), 'web_extension');
        $recorder->record($this->account, $this->message(id: 'a2', name: 'Eu', fromMe: true), 'web_extension');

        $this->assertSame('Maria', WhatsappChat::sole()->display_name);
        $this->assertSame('out', WhatsappMessage::where('external_id', 'a2')->sole()->direction);
    }

    public function test_same_contact_and_message_id_in_two_accounts_are_kept_apart(): void
    {
        $other = $this->makeAccount('GENIUS', 'token-genius');

        $this->recorder()->record($this->account, $this->message(), 'web_extension');
        $status = $this->recorder()->record($other, $this->message(), 'web_extension');

        $this->assertSame('stored', $status);
        $this->assertSame(2, WhatsappChat::count());
        $this->assertSame(2, WhatsappMessage::count());
    }

    public function test_status_and_hostile_jids_are_ignored(): void
    {
        foreach (['status@broadcast', '123@newsletter', '../../x@c.us', ''] as $i => $chat) {
            $this->assertSame('ignored', $this->recorder()->record($this->account, $this->message(id: "x{$i}", chat: $chat), 'web_extension'));
        }

        $this->assertSame(0, WhatsappMessage::count());
        $this->assertSame(0, WhatsappChat::count());
    }

    public function test_audio_media_waits_for_transcription_and_other_media_does_not(): void
    {
        $audio = new IncomingMedia('audio', str_repeat('a', 64), 'local', 'whatsapp/1/a.ogg', 'audio/ogg', 1234, 7);
        $image = new IncomingMedia('image', str_repeat('b', 64), 'local', 'whatsapp/1/b.jpg', 'image/jpeg');

        $this->recorder()->record($this->account, $this->message(id: 'm1', type: 'ptt', media: $audio), 'web_extension');
        $this->recorder()->record($this->account, $this->message(id: 'm2', type: 'image', body: 'legenda', media: $image), 'web_extension');

        $this->assertSame('pending', WhatsappMedia::where('kind', 'audio')->sole()->transcript_status);
        $this->assertNull(WhatsappMedia::where('kind', 'image')->sole()->transcript_status);
        $this->assertSame(7, WhatsappMedia::where('kind', 'audio')->sole()->duration_seconds);
        $this->assertSame(2, Event::where('type', MessageRecorder::EVENT_MEDIA)->count());
        $this->assertSame('legenda', WhatsappMessage::where('external_id', 'm2')->sole()->body);
    }

    public function test_null_characters_do_not_break_postgres(): void
    {
        $status = $this->recorder()->record(
            $this->account,
            $this->message(body: "ol\0á", name: "Ma\0ria"),
            'web_extension',
            ['body' => "ol\0á"],
        );

        $this->assertSame('stored', $status);
        $this->assertSame('olá', WhatsappMessage::sole()->body);
        $this->assertSame(['body' => 'olá'], WhatsappMessage::sole()->payload);
    }

    public function test_authenticate_only_accepts_the_token_of_an_active_account(): void
    {
        $recorder = $this->recorder();

        $this->assertSame($this->account->id, $recorder->authenticate(self::TOKEN)?->id);
        $this->assertNull($recorder->authenticate('errado'));
        $this->assertNull($recorder->authenticate(''));
        $this->assertNull($recorder->authenticate(null));

        $this->account->update(['active' => false]);
        $this->assertNull($recorder->authenticate(self::TOKEN));
    }

    public function test_token_is_stored_only_as_a_hash(): void
    {
        $this->assertNotSame(self::TOKEN, $this->account->token_hash);
        $this->assertSame(64, strlen($this->account->token_hash));
        $this->assertArrayNotHasKey('token_hash', $this->account->toArray());
    }

    public function test_endpoint_in_database_mode_uses_the_account_token(): void
    {
        $endpoint = new MessageEndpoint(new MessageStore($this->dir, 'America/Sao_Paulo'), null, 1024 * 1024, null, $this->recorder());

        $this->assertSame(401, $this->statusOf($endpoint->handle($this->postRequest($this->payload(), 'errado'))));
        $this->assertSame(0, WhatsappMessage::count());

        $stored = $endpoint->handle($this->postRequest($this->payload(), self::TOKEN));
        $this->assertSame(200, $this->statusOf($stored));
        $this->assertSame(['status' => 'stored'], $this->jsonOf($stored));

        $again = $endpoint->handle($this->postRequest($this->payload(), self::TOKEN));
        $this->assertSame(['status' => 'duplicate'], $this->jsonOf($again));

        $this->assertSame(1, WhatsappMessage::count());
        $this->assertFileExists("{$this->dir}/{$this->account->id}/5511999998888.txt");
    }

    public function test_endpoint_in_database_mode_ignores_the_global_token(): void
    {
        $endpoint = new MessageEndpoint(new MessageStore($this->dir, 'America/Sao_Paulo'), 'global', 1024 * 1024, null, $this->recorder());

        $this->assertSame(401, $this->statusOf($endpoint->handle($this->postRequest($this->payload(), 'global'))));
    }

    public function test_endpoint_returns_500_when_the_database_fails_so_the_extension_retries(): void
    {
        $failing = new class extends MessageRecorder
        {
            public function record(WhatsappAccount $account, IncomingMessage $message, string $source, ?array $payload = null): string
            {
                throw new \RuntimeException('banco fora do ar');
            }
        };
        $logged = [];
        $endpoint = new MessageEndpoint(
            new MessageStore($this->dir, 'America/Sao_Paulo'),
            null,
            1024 * 1024,
            function (string $line) use (&$logged) {
                $logged[] = $line;
            },
            $failing,
        );

        $this->assertSame(500, $this->statusOf($endpoint->handle($this->postRequest($this->payload(), self::TOKEN))));
        $this->assertSame(['erro: banco fora do ar'], $logged);
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_sender_jid_is_optional_and_validated(): void
    {
        $base = ['id' => 'a1', 'chat' => '5511999998888@c.us', 'from_me' => false, 'timestamp' => 1790000000];

        $this->assertNull(IncomingMessage::fromArray($base)->senderJid);
        $this->assertNull(IncomingMessage::fromArray($base + ['sender_jid' => null])->senderJid);
        $this->assertNull(IncomingMessage::fromArray($base + ['sender_jid' => ''])->senderJid);
        $this->assertSame('1@lid', IncomingMessage::fromArray($base + ['sender_jid' => '1@lid'])->senderJid);
        $this->assertNull(IncomingMessage::fromArray($base + ['sender_jid' => 123]));
        $this->assertNull(IncomingMessage::fromArray($base + ['sender_jid' => str_repeat('x', 201)]));
    }

    public function test_media_without_a_downloaded_file_is_kept_as_pending_download(): void
    {
        $media = new IncomingMedia('audio', durationSeconds: 12, mimeType: 'audio/ogg; codecs=opus', filename: null, providerMediaId: '1908647269898587');

        $this->recorder()->record($this->account, $this->message(id: 'wamid.X=', type: 'audio', media: $media), 'cloud_api');

        $stored = WhatsappMedia::sole();
        $this->assertSame('pending', $stored->download_status);
        $this->assertNull($stored->storage_path);
        $this->assertNull($stored->sha256);
        $this->assertSame('1908647269898587', $stored->provider_media_id);
        $this->assertSame('pending', $stored->transcript_status);
        $this->assertSame(1, Event::where('type', MessageRecorder::EVENT_MEDIA_PENDING)->count());
        $this->assertSame(0, Event::where('type', MessageRecorder::EVENT_MEDIA)->count());
    }

    public function test_downloaded_media_keeps_filename_and_is_marked_done(): void
    {
        $media = new IncomingMedia('document', str_repeat('c', 64), 'local', 'whatsapp/1/c.pdf', 'application/pdf', 900, null, 'orcamento.pdf');

        $this->recorder()->record($this->account, $this->message(id: 'd1', type: 'document', media: $media), 'web_extension');

        $stored = WhatsappMedia::sole();
        $this->assertSame('done', $stored->download_status);
        $this->assertSame('orcamento.pdf', $stored->filename);
        $this->assertNull($stored->transcript_status);
        $this->assertSame(1, Event::where('type', MessageRecorder::EVENT_MEDIA)->count());
    }

    public function test_quoted_message_and_sent_via_are_stored(): void
    {
        $recorder = $this->recorder();
        $recorder->record($this->account, $this->message(id: 'q1'), 'web_extension');
        $recorder->record($this->account, $this->message(id: 'q2', fromMe: true, quoted: 'q1'), 'web_extension');
        $recorder->record($this->account, $this->message(id: 'q3', fromMe: true, sentVia: 'agent'), 'hermes');

        $this->assertNull(WhatsappMessage::where('external_id', 'q1')->sole()->sent_via);
        $this->assertSame('human', WhatsappMessage::where('external_id', 'q2')->sole()->sent_via);
        $this->assertSame('q1', WhatsappMessage::where('external_id', 'q2')->sole()->quoted_external_id);
        $this->assertSame('agent', WhatsappMessage::where('external_id', 'q3')->sole()->sent_via);
        $this->assertSame('hermes', WhatsappMessage::where('external_id', 'q3')->sole()->source);
    }

    public function test_provider_account_ref_is_unique_per_provider_but_optional(): void
    {
        $store = Store::firstOrCreate(['code' => 'MIXCELL'], ['name' => 'MIXCELL']);
        $make = fn (string $provider, ?string $ref) => WhatsappAccount::create([
            'store_id' => $store->id,
            'label' => 'x',
            'provider' => $provider,
            'provider_account_ref' => $ref,
        ]);

        $make('waha', 'sessao-a');
        $make('evolution', 'sessao-a');
        $make('web_extension', null);
        $make('web_extension', null);

        $this->assertNotNull(WhatsappAccount::where('provider', 'waha')->where('provider_account_ref', 'sessao-a')->first());

        $this->expectException(UniqueConstraintViolationException::class);
        $make('waha', 'sessao-a');
    }

    public function test_quoted_and_sent_via_are_validated_when_they_come_from_the_extension_payload(): void
    {
        $base = ['id' => 'a1', 'chat' => '5511999998888@c.us', 'from_me' => true, 'timestamp' => 1790000000];

        $message = IncomingMessage::fromArray($base + ['quoted_external_id' => 'q1', 'sent_via' => 'agent']);
        $this->assertSame('q1', $message->quotedExternalId);
        $this->assertSame('agent', $message->sentVia);

        $this->assertNull(IncomingMessage::fromArray($base)->quotedExternalId);
        $this->assertSame('x', IncomingMessage::fromArray($base + ['sent_via' => 'x'])->sentVia);
        $this->assertNull(IncomingMessage::fromArray($base + ['sent_via' => str_repeat('x', 17)]));
        $this->assertNull(IncomingMessage::fromArray($base + ['quoted_external_id' => str_repeat('x', 201)]));
        $this->assertNull(IncomingMessage::fromArray($base + ['quoted_external_id' => 5]));
    }

    private function recorder(): MessageRecorder
    {
        return new MessageRecorder;
    }

    private function makeAccount(string $code, string $token): WhatsappAccount
    {
        $store = Store::firstOrCreate(['code' => $code], ['name' => $code]);

        return WhatsappAccount::create([
            'store_id' => $store->id,
            'label' => "Número {$code}",
            'provider' => 'web_extension',
            'token_hash' => WhatsappAccount::hashToken($token),
        ]);
    }

    private function message(
        string $id = 'a1',
        string $chat = '5511999998888@c.us',
        bool $fromMe = false,
        string $name = 'Maria',
        string $body = 'oi',
        string $type = 'chat',
        ?string $senderJid = null,
        ?IncomingMedia $media = null,
        ?string $quoted = null,
        ?string $sentVia = null,
    ): IncomingMessage {
        return new IncomingMessage($id, $chat, $fromMe, $name, $body, $type, 1790000000, $senderJid, $media, $quoted, $sentVia);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): string
    {
        return json_encode($overrides + [
            'id' => 'false_5511999998888@c.us_ABC',
            'chat' => '5511999998888@c.us',
            'from_me' => false,
            'sender_name' => 'Maria',
            'body' => 'oi',
            'type' => 'chat',
            'timestamp' => 1790000000,
        ]);
    }

    private function postRequest(string $body, string $token): string
    {
        return "POST /messages HTTP/1.1\r\nHost: 127.0.0.1\r\nX-Token: {$token}\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\n\r\n{$body}";
    }

    private function statusOf(string $response): int
    {
        return (int) substr($response, 9, 3);
    }

    /** @return array<string, mixed> */
    private function jsonOf(string $response): array
    {
        return json_decode(substr($response, strpos($response, "\r\n\r\n") + 4), true);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            is_dir("{$dir}/{$entry}") ? $this->removeDirectory("{$dir}/{$entry}") : unlink("{$dir}/{$entry}");
        }

        rmdir($dir);
    }
}
