<?php

namespace Tests\Feature;

use App\Jobs\Whatsapp\DownloadWahaMedia;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappMedia;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadWahaMediaTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'chave-da-center';

    private const BASE_URL = 'http://waha-center-reachable.local';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.waha.accounts.center.api_key' => self::API_KEY,
            'whatsapp.waha.accounts.center.base_url' => self::BASE_URL,
        ]);
        Storage::fake('local');
    }

    public function test_downloads_and_stores_the_file_marking_it_done(): void
    {
        // source_url vem com o host interno do próprio WAHA (ex. localhost:3000, não
        // alcançável daqui) — o job tem que reescrever pro base_url configurado antes de buscar.
        Http::fake(['http://waha-center-reachable.local/api/files/CENTER/x.jpg' => Http::response('conteudo-fake-da-imagem', 200)]);

        $media = $this->pendingMedia('http://localhost:3000/api/files/CENTER/x.jpg', 'image/jpeg');

        (new DownloadWahaMedia($media->id))->handle();

        $media->refresh();
        $this->assertSame('done', $media->download_status);
        $this->assertSame('local', $media->storage_disk);
        $this->assertNotNull($media->storage_path);
        $this->assertSame(hash('sha256', 'conteudo-fake-da-imagem'), $media->sha256);
        $this->assertSame(strlen('conteudo-fake-da-imagem'), $media->size_bytes);
        Storage::disk('local')->assertExists($media->storage_path);
        $this->assertStringEndsWith('.jpg', $media->storage_path);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', self::API_KEY));
    }

    public function test_an_expired_link_on_whas_side_is_marked_failed_not_stuck_pending(): void
    {
        Http::fake(['http://waha-center-reachable.local/api/files/CENTER/sumiu.jpg' => Http::response('not found', 404)]);

        $media = $this->pendingMedia('http://waha-center-reachable.local/api/files/CENTER/sumiu.jpg', 'image/jpeg');

        (new DownloadWahaMedia($media->id))->handle();

        $this->assertSame('failed', $media->fresh()->download_status);
    }

    public function test_a_connection_error_is_marked_failed_instead_of_throwing(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timeout');
        });

        $media = $this->pendingMedia('http://waha-center-reachable.local/api/files/CENTER/x.jpg', 'image/jpeg');

        (new DownloadWahaMedia($media->id))->handle();

        $this->assertSame('failed', $media->fresh()->download_status);
    }

    public function test_missing_api_key_configuration_fails_gracefully(): void
    {
        config(['whatsapp.waha.accounts.center.api_key' => null]);
        Http::fake();

        $media = $this->pendingMedia('http://waha-center-reachable.local/api/files/CENTER/x.jpg', 'image/jpeg');

        (new DownloadWahaMedia($media->id))->handle();

        $this->assertSame('failed', $media->fresh()->download_status);
        Http::assertNothingSent();
    }

    public function test_an_already_done_media_is_not_downloaded_again(): void
    {
        Http::fake();

        $media = $this->pendingMedia('http://waha-center-reachable.local/api/files/CENTER/x.jpg', 'image/jpeg');
        $media->update(['download_status' => 'done', 'storage_path' => 'ja-tinha.jpg', 'storage_disk' => 'local']);

        (new DownloadWahaMedia($media->id))->handle();

        Http::assertNothingSent();
        $this->assertSame('ja-tinha.jpg', $media->fresh()->storage_path);
    }

    public function test_a_deleted_media_row_is_a_silent_no_op(): void
    {
        Http::fake();

        (new DownloadWahaMedia(999999))->handle();

        Http::assertNothingSent();
    }

    public function test_the_extension_falls_back_to_the_mime_type_when_the_url_has_none(): void
    {
        Http::fake(['http://waha-center-reachable.local/api/files/CENTER/no-extension' => Http::response('audio-fake', 200)]);

        $media = $this->pendingMedia('http://waha-center-reachable.local/api/files/CENTER/no-extension', 'audio/ogg; codecs=opus');

        (new DownloadWahaMedia($media->id))->handle();

        $this->assertStringEndsWith('.ogg', $media->fresh()->storage_path);
    }

    private function pendingMedia(string $url, string $mimeType): WhatsappMedia
    {
        $store = Store::firstOrCreate(['code' => 'CENTER'], ['name' => 'CENTER']);
        $account = WhatsappAccount::firstOrCreate(
            ['store_id' => $store->id, 'label' => 'WhatsApp CENTER'],
            ['provider' => 'web_extension'],
        );
        $chat = WhatsappChat::create([
            'whatsapp_account_id' => $account->id,
            'chat_key' => '55349999'.random_int(10000, 99999),
            'jid' => '5534999990000@c.us',
            'kind' => 'individual',
        ]);
        $message = WhatsappMessage::create([
            'whatsapp_account_id' => $account->id,
            'whatsapp_chat_id' => $chat->id,
            'external_id' => 'ext-'.random_int(10000, 99999),
            'direction' => 'in',
            'type' => 'image',
            'body' => '',
            'sent_at' => now(),
            'source' => 'waha',
        ]);

        return WhatsappMedia::create([
            'whatsapp_message_id' => $message->id,
            'kind' => 'image',
            'mime_type' => $mimeType,
            'source_url' => $url,
            'download_status' => 'pending',
        ]);
    }
}
