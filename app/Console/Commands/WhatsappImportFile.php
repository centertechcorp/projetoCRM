<?php

namespace App\Console\Commands;

use App\Models\WhatsappAccount;
use App\Services\Whatsapp\IncomingMessage;
use App\Services\Whatsapp\MessageRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Importa um export manual de mensagens (ex.: dump do bot de outro desenvolvedor) para
 * as mesmas tabelas do resto do projeto, pelo MessageRecorder — sem depender de nenhum
 * webhook em tempo real. Aceita o mesmo formato que a extensão do Chrome já usa
 * (App\Services\Whatsapp\IncomingMessage::fromArray): id, chat, from_me, sender_name,
 * body, type, timestamp, e opcionalmente sender_jid, quoted_external_id, sent_via.
 */
#[Signature('whatsapp:import:file {path : Caminho do arquivo .json ou .csv} {--account= : ID ou label exato da conta dona dessas mensagens} {--source=export : Valor gravado em whatsapp_messages.source}')]
#[Description('Importa um export manual de mensagens (JSON ou CSV) para o banco')]
class WhatsappImportFile extends Command
{
    public function handle(MessageRecorder $recorder): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->components->error("Arquivo não encontrado: {$path}");

            return self::FAILURE;
        }

        $account = $this->resolveAccount();

        if ($account === null) {
            return self::FAILURE;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $rows = match ($extension) {
            'json' => $this->readJson($path),
            'csv' => $this->readCsv($path),
            default => null,
        };

        if ($rows === null) {
            $this->components->error('Use um arquivo .json (lista de objetos) ou .csv (com cabeçalho).');

            return self::FAILURE;
        }

        $source = (string) $this->option('source');
        $counts = ['stored' => 0, 'duplicate' => 0, 'ignored' => 0, 'invalid' => 0, 'error' => 0];

        foreach ($rows as $row) {
            $message = IncomingMessage::fromArray($this->normalizeRow($row));

            if ($message === null) {
                $counts['invalid']++;

                continue;
            }

            try {
                $status = $recorder->record($account, $message, $source, is_array($row) ? $row : null);
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            } catch (\Throwable) {
                $counts['error']++;
            }
        }

        $this->components->info(sprintf(
            'Importado: %d gravada(s), %d duplicada(s), %d ignorada(s), %d inválida(s), %d com erro.',
            $counts['stored'],
            $counts['duplicate'],
            $counts['ignored'],
            $counts['invalid'],
            $counts['error'],
        ));

        return self::SUCCESS;
    }

    private function resolveAccount(): ?WhatsappAccount
    {
        $option = $this->option('account');

        if ($option === null) {
            $this->components->error('Informe --account com o ID ou o label exato da conta.');

            return null;
        }

        $account = ctype_digit($option)
            ? WhatsappAccount::find((int) $option)
            : WhatsappAccount::query()->where('label', $option)->first();

        if ($account === null) {
            $this->components->error("Conta não encontrada para --account={$option}.");

            return null;
        }

        return $account;
    }

    /** @return ?array<int, mixed> */
    private function readJson(string $path): ?array
    {
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && array_is_list($data) ? $data : null;
    }

    /** @return ?array<int, array<string, string>> */
    private function readCsv(string $path): ?array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return null;
        }

        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) !== count($header)) {
                continue;
            }

            $rows[] = array_combine($header, $line);
        }

        fclose($handle);

        return $rows;
    }

    /** @param  mixed  $row  @return array<string, mixed> */
    private function normalizeRow(mixed $row): array
    {
        if (! is_array($row)) {
            return [];
        }

        $fromMe = $row['from_me'] ?? null;

        if (is_string($fromMe)) {
            $fromMe = in_array(strtolower(trim($fromMe)), ['1', 'true', 'yes', 'sim'], true);
        }

        $timestamp = $row['timestamp'] ?? null;

        if (is_string($timestamp) && ctype_digit($timestamp)) {
            $timestamp = (int) $timestamp;
        }

        return [...$row, 'from_me' => $fromMe, 'timestamp' => $timestamp];
    }
}
