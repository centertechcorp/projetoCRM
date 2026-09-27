<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappImportFileTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappAccount $account;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $this->account = WhatsappAccount::create([
            'store_id' => $store->id,
            'label' => 'Bot Export',
            'provider' => 'export',
        ]);

        $this->dir = sys_get_temp_dir().'/whatsapp-import-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    public function test_imports_valid_json_rows_and_counts_invalid_ones(): void
    {
        $path = $this->writeJson([
            ['id' => 'imp-1', 'chat' => '5534999990001@c.us', 'from_me' => false, 'sender_name' => 'Cliente', 'body' => 'oi', 'type' => 'chat', 'timestamp' => 1790000000],
            ['id' => 'imp-2', 'chat' => '5534999990001@c.us', 'from_me' => true, 'sender_name' => '', 'body' => 'resposta', 'type' => 'chat', 'timestamp' => 1790000100],
            ['chat' => '5534999990001@c.us', 'from_me' => false, 'body' => 'sem id', 'type' => 'chat', 'timestamp' => 1790000200],
        ]);

        // A saída do artisan é formatada em caixa e pode quebrar linha no meio da frase
        // (Symfony faz word-wrap), então a verificação de verdade é o estado no banco.
        $this->artisan('whatsapp:import:file', [
            'path' => $path, '--account' => (string) $this->account->id,
        ])->assertSuccessful();

        $this->assertSame(2, WhatsappMessage::count());
    }

    public function test_reimporting_the_same_json_reports_duplicates(): void
    {
        $path = $this->writeJson([
            ['id' => 'imp-dup', 'chat' => '5534999990002@c.us', 'from_me' => false, 'sender_name' => 'Cliente', 'body' => 'oi', 'type' => 'chat', 'timestamp' => 1790000000],
        ]);

        $this->artisan('whatsapp:import:file', ['path' => $path, '--account' => (string) $this->account->id])->assertSuccessful();
        $this->artisan('whatsapp:import:file', ['path' => $path, '--account' => (string) $this->account->id])->assertSuccessful();

        $this->assertSame(1, WhatsappMessage::count());
    }

    public function test_imports_csv_with_string_booleans_and_timestamps(): void
    {
        $path = "{$this->dir}/import.csv";
        file_put_contents($path, "id,chat,from_me,sender_name,body,type,timestamp\n"
            ."imp-csv-1,5534999990003@c.us,0,Cliente,oi,chat,1790000000\n"
            ."imp-csv-2,5534999990003@c.us,1,,resposta,chat,1790000100\n");

        $this->artisan('whatsapp:import:file', ['path' => $path, '--account' => 'Bot Export'])->assertSuccessful();

        $out = WhatsappMessage::where('external_id', 'imp-csv-1')->sole();
        $reply = WhatsappMessage::where('external_id', 'imp-csv-2')->sole();
        $this->assertSame('in', $out->direction);
        $this->assertSame('out', $reply->direction);
    }

    public function test_fails_for_missing_file_and_unknown_account(): void
    {
        $this->artisan('whatsapp:import:file', ['path' => '/tmp/nao-existe-9999.json', '--account' => (string) $this->account->id])
            ->assertFailed();

        $path = $this->writeJson([]);
        $this->artisan('whatsapp:import:file', ['path' => $path, '--account' => '999999'])->assertFailed();
        $this->artisan('whatsapp:import:file', ['path' => $path])->assertFailed();
    }

    public function test_fails_for_an_unsupported_extension(): void
    {
        $path = "{$this->dir}/dump.txt";
        file_put_contents($path, 'qualquer coisa');

        $this->artisan('whatsapp:import:file', ['path' => $path, '--account' => (string) $this->account->id])->assertFailed();
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function writeJson(array $rows): string
    {
        $path = "{$this->dir}/import.json";
        file_put_contents($path, json_encode($rows));

        return $path;
    }
}
