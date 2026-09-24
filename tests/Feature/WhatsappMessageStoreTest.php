<?php

namespace Tests\Feature;

use App\Services\Whatsapp\IncomingMessage;
use App\Services\Whatsapp\MessageStore;
use Tests\TestCase;

class WhatsappMessageStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/whatsapp-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);

        parent::tearDown();
    }

    public function test_appends_to_a_file_per_contact_keeping_direction(): void
    {
        $store = $this->store();

        $this->assertSame('stored', $store->append($this->message(id: 'a1', body: 'oi')));
        $this->assertSame('stored', $store->append($this->message(id: 'a2', fromMe: true, body: 'olá, tudo bem?')));
        $this->assertSame('stored', $store->append($this->message(id: 'b1', chat: '5511977776666@c.us', name: 'João', body: 'e aí')));

        $this->assertSame(
            "[2026-09-21 11:13:20] in | Maria: oi\n[2026-09-21 11:13:20] out | Maria: olá, tudo bem?\n",
            file_get_contents("{$this->dir}/5511999998888.txt"),
        );
        $this->assertSame(
            "[2026-09-21 11:13:20] in | João: e aí\n",
            file_get_contents("{$this->dir}/5511977776666.txt"),
        );
    }

    public function test_appending_never_rewrites_existing_content(): void
    {
        mkdir($this->dir, 0750, true);
        file_put_contents("{$this->dir}/5511999998888.txt", "linha antiga\n");

        $this->store()->append($this->message(id: 'a1', body: 'nova'));

        $this->assertStringStartsWith("linha antiga\n[", file_get_contents("{$this->dir}/5511999998888.txt"));
    }

    public function test_repeated_id_is_not_written_twice_even_after_a_restart(): void
    {
        $message = $this->message(id: 'a1', body: 'oi');

        $this->assertSame('stored', $this->store()->append($message));
        $this->assertSame('duplicate', $this->store()->append($message));

        $this->assertCount(1, file("{$this->dir}/5511999998888.txt"));
    }

    public function test_line_breaks_are_escaped_to_keep_one_line_per_message(): void
    {
        $this->store()->append($this->message(id: 'a1', body: "linha 1\r\nlinha 2\nlinha 3"));

        $this->assertStringContainsString('Maria: linha 1\nlinha 2\nlinha 3'.PHP_EOL, file_get_contents("{$this->dir}/5511999998888.txt"));
        $this->assertCount(1, file("{$this->dir}/5511999998888.txt"));
    }

    public function test_media_messages_use_a_label_and_keep_the_caption(): void
    {
        $store = $this->store();
        $store->append($this->message(id: 'a1', body: '', type: 'image'));
        $store->append($this->message(id: 'a2', body: 'olha isso', type: 'image'));
        $store->append($this->message(id: 'a3', body: '', type: 'ptt'));

        $lines = file("{$this->dir}/5511999998888.txt", FILE_IGNORE_NEW_LINES);

        $this->assertStringEndsWith('Maria: [imagem]', $lines[0]);
        $this->assertStringEndsWith('Maria: [imagem] olha isso', $lines[1]);
        $this->assertStringEndsWith('Maria: [áudio]', $lines[2]);
    }

    public function test_groups_go_to_their_own_file(): void
    {
        $this->store()->append($this->message(id: 'g1', chat: '120363025246125486@g.us', body: 'oi grupo'));

        $this->assertFileExists("{$this->dir}/grupo_120363025246125486.txt");
    }

    public function test_device_suffix_in_the_jid_maps_to_the_same_contact(): void
    {
        $this->store()->append($this->message(id: 'a1', chat: '5511999998888:12@c.us'));

        $this->assertFileExists("{$this->dir}/5511999998888.txt");
    }

    public function test_status_broadcast_and_hostile_jids_are_ignored(): void
    {
        $store = $this->store();

        foreach (['status@broadcast', '123@newsletter', '../../etc/passwd@c.us', '5511/../x@c.us', ''] as $i => $chat) {
            $this->assertSame('ignored', $store->append($this->message(id: "x{$i}", chat: $chat)));
        }

        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_missing_sender_name_falls_back_to_contact_or_me(): void
    {
        $store = $this->store();
        $store->append($this->message(id: 'a1', name: '', body: 'oi'));
        $store->append($this->message(id: 'a2', name: '', fromMe: true, body: 'oi'));

        $lines = file("{$this->dir}/5511999998888.txt", FILE_IGNORE_NEW_LINES);

        $this->assertStringContainsString('in | 5511999998888: oi', $lines[0]);
        $this->assertStringContainsString('out | Eu: oi', $lines[1]);
    }

    private function store(): MessageStore
    {
        return new MessageStore($this->dir, 'America/Sao_Paulo');
    }

    private function message(
        string $id = 'a1',
        string $chat = '5511999998888@c.us',
        bool $fromMe = false,
        string $name = 'Maria',
        string $body = 'oi',
        string $type = 'chat',
    ): IncomingMessage {
        return new IncomingMessage($id, $chat, $fromMe, $name, $body, $type, 1790000000);
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
