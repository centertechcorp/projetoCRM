<?php

namespace Tests\Feature;

use App\Services\Whatsapp\HttpRequest;
use App\Services\Whatsapp\MessageEndpoint;
use App\Services\Whatsapp\MessageStore;
use Tests\TestCase;

class WhatsappEndpointTest extends TestCase
{
    private const MAX_BODY = 1024;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/whatsapp-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/{,.seen/}*", GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir("{$this->dir}/.seen");
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_valid_message_is_stored(): void
    {
        $response = $this->endpoint()->handle($this->postRequest($this->payload(), 'segredo'));

        $this->assertSame(200, $this->statusOf($response));
        $this->assertSame(['status' => 'stored'], $this->jsonOf($response));
        $this->assertFileExists("{$this->dir}/5511999998888.txt");
    }

    public function test_resending_the_same_message_reports_duplicate(): void
    {
        $endpoint = $this->endpoint();
        $endpoint->handle($this->postRequest($this->payload(), 'segredo'));

        $response = $endpoint->handle($this->postRequest($this->payload(), 'segredo'));

        $this->assertSame(['status' => 'duplicate'], $this->jsonOf($response));
        $this->assertCount(1, file("{$this->dir}/5511999998888.txt"));
    }

    public function test_missing_or_wrong_token_is_rejected(): void
    {
        $this->assertSame(401, $this->statusOf($this->endpoint()->handle($this->postRequest($this->payload(), null))));
        $this->assertSame(401, $this->statusOf($this->endpoint()->handle($this->postRequest($this->payload(), 'errado'))));
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_endpoint_without_configured_token_rejects_everything(): void
    {
        $endpoint = new MessageEndpoint($this->store(), null, self::MAX_BODY);

        $this->assertSame(401, $this->statusOf($endpoint->handle($this->postRequest($this->payload(), ''))));
    }

    public function test_content_length_is_read_when_it_is_not_the_last_header(): void
    {
        $body = $this->payload();
        $raw = "POST /messages HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Length: ".strlen($body)."\r\nX-Token: segredo\r\nContent-Type: application/json\r\n\r\n{$body}";

        $this->assertTrue(HttpRequest::isComplete($raw, self::MAX_BODY));
        $this->assertFalse(HttpRequest::isComplete(substr($raw, 0, -5), self::MAX_BODY));
        $this->assertSame(200, $this->statusOf($this->endpoint()->handle($raw)));
    }

    public function test_invalid_json_and_invalid_fields_return_400(): void
    {
        $this->assertSame(400, $this->statusOf($this->endpoint()->handle($this->postRequest('{nao e json', 'segredo'))));
        $this->assertSame(400, $this->statusOf($this->endpoint()->handle($this->postRequest('{"id":"a1"}', 'segredo'))));
        $this->assertSame(400, $this->statusOf($this->endpoint()->handle('lixo')));
    }

    public function test_body_over_the_limit_returns_413(): void
    {
        $body = json_encode(['body' => str_repeat('x', self::MAX_BODY + 1)]);

        $this->assertSame(413, $this->statusOf($this->endpoint()->handle($this->postRequest($body, 'segredo'))));
    }

    public function test_status_broadcast_is_acknowledged_but_ignored(): void
    {
        $response = $this->endpoint()->handle($this->postRequest($this->payload(['chat' => 'status@broadcast']), 'segredo'));

        $this->assertSame(['status' => 'ignored'], $this->jsonOf($response));
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_health_and_unknown_routes(): void
    {
        $endpoint = $this->endpoint();

        $this->assertSame(200, $this->statusOf($endpoint->handle("GET /health HTTP/1.1\r\nHost: x\r\n\r\n")));
        $this->assertSame(404, $this->statusOf($endpoint->handle("GET /outra HTTP/1.1\r\nHost: x\r\n\r\n")));
        $this->assertSame(405, $this->statusOf($endpoint->handle("GET /messages HTTP/1.1\r\nHost: x\r\n\r\n")));
    }

    public function test_request_completeness_detection(): void
    {
        $full = $this->postRequest($this->payload(), 'segredo');

        $this->assertFalse(HttpRequest::isComplete("POST /messages HTTP/1.1\r\nContent-Length: 10\r\n", self::MAX_BODY));
        $this->assertFalse(HttpRequest::isComplete(substr($full, 0, -5), self::MAX_BODY));
        $this->assertTrue(HttpRequest::isComplete($full, self::MAX_BODY));
        $this->assertTrue(HttpRequest::isComplete("POST /messages HTTP/1.1\r\nContent-Length: 999999\r\n\r\n", self::MAX_BODY));
        $this->assertTrue(HttpRequest::isComplete("POST /messages HTTP/1.1\r\nContent-Length: abc\r\n\r\n", self::MAX_BODY));
    }

    private function store(): MessageStore
    {
        return new MessageStore($this->dir, 'America/Sao_Paulo');
    }

    private function endpoint(): MessageEndpoint
    {
        return new MessageEndpoint($this->store(), 'segredo', self::MAX_BODY);
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

    private function postRequest(string $body, ?string $token): string
    {
        $auth = $token === null ? '' : "X-Token: {$token}\r\n";

        return "POST /messages HTTP/1.1\r\nHost: 127.0.0.1\r\n{$auth}Content-Length: ".strlen($body)."\r\n\r\n{$body}";
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
}
