<?php

namespace App\Jobs\Whatsapp;

use App\Models\WhatsappMedia;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Baixa de verdade o arquivo de mídia que o WAHA aponta em media.url (WahaAdapter) e salva no
 * disco configurado (FILESYSTEM_DISK — local hoje, S3 quando configurado). O link do WAHA
 * expira depois de um tempo do lado deles, por isso isso roda logo depois de a mensagem chegar,
 * não em lote depois.
 */
class DownloadWahaMedia implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(private readonly int $whatsappMediaId) {}

    public function handle(): void
    {
        $media = WhatsappMedia::find($this->whatsappMediaId);

        if ($media === null || $media->download_status !== 'pending' || $media->source_url === null) {
            return;
        }

        $config = $this->wahaConfigFor($media);

        if ($config === null) {
            Log::warning("DownloadWahaMedia: não achei a config do WAHA pra mídia {$media->id}.");
            $media->update(['download_status' => 'failed']);

            return;
        }

        [$apiKey, $baseUrl] = $config;

        // O WAHA manda a URL com o host dele mesmo por dentro (ex. localhost:3000), que não é
        // alcançável daqui de fora — troca pelo host configurado (config/whatsapp.php "waha"),
        // mantendo só o caminho do arquivo.
        $path = parse_url($media->source_url, PHP_URL_PATH) ?? '';
        $fetchUrl = rtrim($baseUrl, '/').$path;

        try {
            $response = Http::withHeaders(['X-Api-Key' => $apiKey])->timeout(30)->get($fetchUrl);

            if (! $response->successful()) {
                // Mais comum: o arquivo já expirou do lado do WAHA (eles guardam por um tempo só).
                Log::warning("DownloadWahaMedia: falha ao baixar mídia {$media->id} (status {$response->status()}, url {$fetchUrl}).");
                $media->update(['download_status' => 'failed']);

                return;
            }

            $bytes = $response->body();
            $disk = config('filesystems.default');
            $extension = $this->extensionFor($media->mime_type, $media->source_url);
            $path = "whatsapp-media/{$media->whatsapp_message_id}-{$media->id}.{$extension}";

            Storage::disk($disk)->put($path, $bytes);

            $media->update([
                'storage_disk' => $disk,
                'storage_path' => $path,
                'sha256' => hash('sha256', $bytes),
                'size_bytes' => strlen($bytes),
                'download_status' => 'done',
            ]);
        } catch (Throwable $e) {
            Log::warning("DownloadWahaMedia: erro baixando mídia {$media->id}: {$e->getMessage()}");
            $media->update(['download_status' => 'failed']);
        }
    }

    /** @return array{0: string, 1: string}|null [api_key, base_url] */
    private function wahaConfigFor(WhatsappMedia $media): ?array
    {
        $storeCode = $media->message?->account?->store?->code;

        if (! is_string($storeCode)) {
            return null;
        }

        $account = config('whatsapp.waha.accounts.'.strtolower($storeCode));
        $apiKey = $account['api_key'] ?? null;
        $baseUrl = $account['base_url'] ?? null;

        if (! is_string($apiKey) || $apiKey === '' || ! is_string($baseUrl) || $baseUrl === '') {
            return null;
        }

        return [$apiKey, $baseUrl];
    }

    private function extensionFor(?string $mimeType, string $url): string
    {
        $fromUrl = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);

        if ($fromUrl !== '') {
            return $fromUrl;
        }

        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/mpeg' => 'mp3',
            'video/mp4' => 'mp4',
            'application/pdf' => 'pdf',
            default => Str::after((string) $mimeType, '/') ?: 'bin',
        };
    }
}
