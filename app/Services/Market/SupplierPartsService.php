<?php

namespace App\Services\Market;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consulta (só leitura) o banco do fornecedor Mix Atacado — tabela `produtos_wefix`, o
 * catálogo cheio de peça (tela, bateria, placa de carga etc.) com preço e estoque. Nunca
 * escreve nada lá, é a base deles, não a nossa.
 */
class SupplierPartsService
{
    private const CATEGORIES = [
        'Tela' => ['FR ', 'FRONTAL'],
        'Bateria' => ['BAT', 'BATERIA'],
        'Placa de carga' => ['PLACA CARGA'],
        'Capinha/Capa' => ['CAPINHA', 'CAPA'],
        'Tampa' => ['TAMPA'],
        'Flex' => ['FLEX'],
        'Câmera' => ['CAMERA', 'CÂMERA'],
        'Campainha' => ['CAMPAINHA'],
        'Fone' => ['FONE'],
        'Kit' => ['KIT'],
        'Lente' => ['LENTE'],
        'Cabo' => ['CABO'],
        'Película' => ['PELICULA', 'PELÍCULA'],
        'Dock' => ['DOCK'],
        'Conector' => ['CONECTOR'],
        'Fonte' => ['FONTE'],
        'Carregador' => ['CARR'],
    ];

    private const BRANDS = ['WEFIX', 'WEKEEP', 'SKAIKY', 'SKAIK', 'DEJI', 'NN', 'ORIGINAL', 'WK'];

    private const CACHE_MINUTES = 30;

    /**
     * Agrupado por categoria (pra paginar no painel, uma categoria por vez), cada uma com
     * suas linhas de marca já ordenadas. Categoria com mais item vem primeiro.
     *
     * @return array<string, array<int, array{marca: string, qtd: int, min: float, max: float}>>|null
     */
    public function summaryByCategory(): ?array
    {
        return Cache::remember('market.supplier_parts_summary', now()->addMinutes(self::CACHE_MINUTES), function () {
            $rows = $this->fetchAll();

            if ($rows === null) {
                return null;
            }

            $grouped = [];

            foreach ($rows as $row) {
                $categoria = $this->categoriaOf($row->nome);

                if ($categoria === null) {
                    continue;
                }

                $marca = $this->marcaOf($row->nome);
                $preco = (float) $row->valor_venda;

                if ($preco <= 0) {
                    continue;
                }

                $key = $categoria.'|'.$marca;
                $grouped[$key] ??= ['categoria' => $categoria, 'marca' => $marca, 'qtd' => 0, 'min' => $preco, 'max' => $preco];
                $grouped[$key]['qtd']++;
                $grouped[$key]['min'] = min($grouped[$key]['min'], $preco);
                $grouped[$key]['max'] = max($grouped[$key]['max'], $preco);
            }

            $byCategory = [];

            foreach ($grouped as $row) {
                $byCategory[$row['categoria']][] = [
                    'marca' => $row['marca'],
                    'qtd' => $row['qtd'],
                    'min' => $row['min'],
                    'max' => $row['max'],
                ];
            }

            foreach ($byCategory as &$linhas) {
                usort($linhas, fn ($a, $b) => $a['marca'] <=> $b['marca']);
            }
            unset($linhas);

            uasort($byCategory, fn ($a, $b) => array_sum(array_column($b, 'qtd')) <=> array_sum(array_column($a, 'qtd')));

            return $byCategory;
        });
    }

    /** @return Collection<int, array{nome: string, categoria: ?string, marca: string, valor_custo: float, valor_venda: float, estoque: int}> */
    public function search(string $term): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        try {
            return DB::connection('mix_atacado')
                ->table('produtos_wefix')
                ->where('nome', 'like', "%{$term}%")
                ->orderBy('nome')
                ->limit(30)
                ->get()
                ->map(fn ($row) => [
                    'nome' => $row->nome,
                    'categoria' => $this->categoriaOf($row->nome),
                    'marca' => $this->marcaOf($row->nome),
                    'valor_custo' => (float) $row->valor_custo,
                    'valor_venda' => (float) $row->valor_venda,
                    'estoque' => (int) $row->estoque,
                ]);
        } catch (Throwable $e) {
            Log::warning('Não consegui buscar peças no banco do fornecedor: '.$e->getMessage());

            return collect();
        }
    }

    /** @return Collection<int, object>|null */
    private function fetchAll(): ?Collection
    {
        try {
            return DB::connection('mix_atacado')->table('produtos_wefix')->select('nome', 'valor_venda')->get();
        } catch (Throwable $e) {
            Log::warning('Não consegui buscar o catálogo do fornecedor: '.$e->getMessage());

            return null;
        }
    }

    private function categoriaOf(string $nome): ?string
    {
        $upper = mb_strtoupper($nome);

        foreach (self::CATEGORIES as $categoria => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($upper, $prefix) || str_contains($upper, ' '.trim($prefix))) {
                    return $categoria;
                }
            }
        }

        return null;
    }

    private function marcaOf(string $nome): string
    {
        $upper = mb_strtoupper($nome);

        foreach (self::BRANDS as $brand) {
            if (str_contains($upper, $brand)) {
                return $brand === 'SKAIKY' ? 'SKAIK' : ($brand === 'WK' ? 'WEKEEP' : $brand);
            }
        }

        return 'Outra';
    }
}
