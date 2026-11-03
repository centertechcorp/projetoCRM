<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copia os dados do PostgreSQL (conexão padrão) para um MariaDB que JÁ tem o esquema migrado
 * (php artisan migrate --database=mariadb_target). Só LÊ do Postgres; escreve só no destino.
 * Recusa rodar se o destino já tiver dados, copia tudo numa transação (falhou, desfaz) e no fim
 * confere a contagem de linhas de cada tabela.
 */
class DbCopyPgToMariadb extends Command
{
    protected $signature = 'db:copy-pg-to-mariadb
        {--from=pgsql : Conexão de origem (Postgres)}
        {--to=mariadb_target : Conexão de destino (MariaDB, esquema já migrado e vazio)}
        {--chunk=500 : Linhas por lote}
        {--dry-run : Só mostra o que seria copiado, sem escrever nada}';

    protected $description = 'Copia os dados do Postgres para um MariaDB já migrado (confere a contagem no fim)';

    /** Dados efêmeros ou controlados pelo Laravel no destino: não se copiam. */
    private const SKIP = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'failed_jobs'];

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $dry = (bool) $this->option('dry-run');

        if (DB::connection($from)->getDriverName() !== 'pgsql') {
            $this->error("A origem '{$from}' precisa ser PostgreSQL.");

            return self::FAILURE;
        }

        if (! in_array(DB::connection($to)->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->error("O destino '{$to}' precisa ser MariaDB/MySQL.");

            return self::FAILURE;
        }

        $tables = $this->orderedTables($from);
        $this->info('Origem: '.DB::connection($from)->getDatabaseName().'  →  Destino: '.DB::connection($to)->getDatabaseName().($dry ? '  (dry-run)' : ''));

        foreach ($tables as $table) {
            if (! Schema::connection($to)->hasTable($table)) {
                $this->error("A tabela '{$table}' não existe no destino. Rode antes: php artisan migrate --database={$to} --force");

                return self::FAILURE;
            }

            if (DB::connection($to)->table($table)->exists()) {
                $this->error("O destino já tem dados em '{$table}'. Esta cópia só roda em banco vazio (nada foi alterado).");

                return self::FAILURE;
            }
        }

        if ($dry) {
            foreach ($tables as $table) {
                $this->line(sprintf('  %-28s %d linhas', $table, DB::connection($from)->table($table)->count()));
            }
            $this->info('Dry-run: nada foi escrito.');

            return self::SUCCESS;
        }

        $target = DB::connection($to);

        try {
            $target->transaction(function () use ($tables, $from, $target, $to) {
                Schema::connection($to)->disableForeignKeyConstraints();

                foreach ($tables as $table) {
                    $copied = $this->copyTable($from, $to, $table);
                    $this->line(sprintf('  %-28s %d linhas copiadas', $table, $copied));
                }

                Schema::connection($to)->enableForeignKeyConstraints();
            });
        } catch (\Throwable $e) {
            $this->error('Falhou e foi desfeito (o destino ficou como estava): '.$e->getMessage());

            return self::FAILURE;
        }

        return $this->verify($from, $to, $tables) ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> tabelas em ordem de dependência (pai antes de filho) */
    private function orderedTables(string $from): array
    {
        $names = collect(Schema::connection($from)->getTables())
            ->pluck('name')
            ->reject(fn ($t) => in_array($t, self::SKIP, true))
            ->values()
            ->all();

        $deps = [];
        foreach ($names as $table) {
            $deps[$table] = collect(Schema::connection($from)->getForeignKeys($table))
                ->pluck('foreign_table')
                ->unique()
                ->reject(fn ($t) => $t === $table || ! in_array($t, $names, true))
                ->values()
                ->all();
        }

        $ordered = [];
        while ($deps !== []) {
            $ready = array_keys(array_filter($deps, fn ($d) => array_diff($d, $ordered) === []));

            if ($ready === []) {
                $ready = [array_key_first($deps)]; // ciclo: segue, as FKs ficam desligadas na cópia
            }

            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($deps[$table]);
            }
        }

        return $ordered;
    }

    private function copyTable(string $from, string $to, string $table): int
    {
        $columns = Schema::connection($from)->getColumns($table);
        $timeColumns = collect($columns)
            ->filter(fn ($c) => in_array($c['type_name'], ['timestamptz', 'timestamp'], true))
            ->pluck('name')
            ->all();
        $hasId = collect($columns)->contains(fn ($c) => $c['name'] === 'id');
        $chunk = max(1, (int) $this->option('chunk'));
        $count = 0;

        $insert = function ($rows) use ($to, $table, $timeColumns, &$count) {
            $batch = $rows->map(function ($row) use ($timeColumns) {
                $row = (array) $row;
                foreach ($timeColumns as $col) {
                    if ($row[$col] !== null) {
                        // O Laravel grava no MariaDB no fuso do app; o Postgres devolve com offset.
                        $row[$col] = Carbon::parse($row[$col])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
                    }
                }

                return $row;
            })->all();

            DB::connection($to)->table($table)->insert($batch);
            $count += count($batch);
        };

        $query = DB::connection($from)->table($table);

        if ($hasId) {
            $query->orderBy('id')->chunkById($chunk, $insert);
        } else {
            $query->get()->chunk($chunk)->each($insert);
        }

        return $count;
    }

    /** @param  list<string>  $tables */
    private function verify(string $from, string $to, array $tables): bool
    {
        $ok = true;
        $this->newLine();
        $this->info('Conferência (origem × destino):');

        foreach ($tables as $table) {
            $a = DB::connection($from)->table($table)->count();
            $b = DB::connection($to)->table($table)->count();
            $same = $a === $b;
            $ok = $ok && $same;
            $this->line(sprintf('  %-28s %8d %8d  %s', $table, $a, $b, $same ? 'ok' : 'DIFERENTE'));
        }

        $ok ? $this->info('Tudo conferido: as contagens batem.') : $this->error('Contagens diferentes — NÃO use o destino.');

        return $ok;
    }
}
