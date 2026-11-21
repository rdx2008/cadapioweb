<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CatalogExport
{
    public const MAX_CELLS = 60000;

    private function rows(Builder $query, int $limit = 5000): Collection
    {
        $rows = $query->orderBy('id')->limit($limit + 1)->get();
        abort_if($rows->count() > $limit, 422, 'O catálogo excede o limite de exportação. Solicite à administração uma exportação em partes.');

        return $rows;
    }

    public function snapshot(object $store, string $baseUrl): array
    {
        $products = $this->rows(DB::table('products')->where('tenant_id', $store->tenant_id)->where('store_id', $store->id), 2000)->keyBy('id');
        $categories = $this->rows(DB::table('catalog_categories')->where('store_id', $store->id));
        $variants = $this->rows(DB::table('product_variants')->whereIn('product_id', $products->keys()))->keyBy('id');
        $groups = $this->rows(DB::table('option_groups')->whereIn('product_id', $products->keys()))->keyBy('id');
        $options = $this->rows(DB::table('product_options')->whereIn('option_group_id', $groups->keys()));
        $flavors = $this->rows(DB::table('pizza_flavors')->whereIn('product_id', $products->keys()));
        $comboGroups = $this->rows(DB::table('combo_groups')->whereIn('product_id', $products->keys()))->keyBy('id');
        $comboOptions = $this->rows(DB::table('combo_options')->whereIn('combo_group_id', $comboGroups->keys()));
        $tables = [];
        $cells = 0;
        $add = function (string $name, array $headers, iterable $rows, array $money = []) use (&$tables, &$cells): void {
            $rows = collect($rows)->values()->all();
            $cells += (count($rows) + 1) * count($headers);
            abort_if($cells > self::MAX_CELLS || count($rows) > 5000, 422, 'O catálogo excede 60.000 células ou 5.000 linhas por aba. Solicite uma exportação em partes à administração.');
            $tables[] = compact('name', 'headers', 'rows', 'money');
        };
        $yes = fn (mixed $value): string => $value ? 'Sim' : 'Não';
        $add('Instrucoes', ['Informação', 'Valor'], [
            ['Formato', 'CadapioWeb · catálogo completo · versão 1'], ['Loja', $store->name],
            ['Gerado em UTC', now()->format('Y-m-d H:i:s')], ['Cardápio', $baseUrl.'/cardapio/'.rawurlencode($store->slug)],
            ['Conteúdo', 'Todos os produtos da loja, inclusive pausados; filtros da tela não limitam este arquivo.'],
            ['Identificadores', 'IDs relacionam as abas e pertencem a esta instalação.'],
            ['Preços', 'Valores em reais. Combos mostram o acréscimo por unidade do componente.'],
            ['Fotos', 'Links públicos; arquivos de imagem não são incorporados.'],
            ['Uso', 'Arquivo de consulta e conferência. Não restaura automaticamente o catálogo pela importação.'],
            ['Limites', '2.000 produtos, 5.000 linhas por aba e 60.000 células no arquivo.'],
        ]);
        $add('Categorias', ['ID categoria', 'Categoria', 'Ordem'], $categories->map(fn ($row) => [$row->id, $row->name, $row->sort_order]));
        $add('Produtos', ['ID produto', 'ID categoria', 'Categoria', 'Produto', 'Descrição', 'Tipo', 'Preço R$', 'Habilitado', 'Destaque', 'Ordem', 'Usa variações', 'Pizza habilitada', 'Regra pizza', 'Combo fixo', 'Estação de preparo', 'Link da foto'], $products->map(fn ($p) => [$p->id, $p->category_id, $p->category, $p->name, $p->description, $p->product_type, $p->price_cents / 100, $yes($p->available), $yes($p->featured), $p->sort_order, $yes($p->uses_variants), $yes($p->pizza_enabled), $p->pizza_pricing, $yes($p->combo_fixed), $p->kitchen_station, $p->photo_path ? $baseUrl.'/media/products/'.$p->id : null]), [7]);
        $add('Variacoes', ['ID variação', 'ID produto', 'Produto', 'Variação', 'Preço R$', 'Habilitada', 'Máximo de sabores'], $variants->map(fn ($v) => [$v->id, $v->product_id, $products[$v->product_id]->name, $v->name, $v->price_cents / 100, $yes($v->available), $v->max_flavors]), [5]);
        $add('Grupos adicionais', ['ID grupo', 'ID produto', 'Produto', 'Grupo', 'Tipo', 'Mínimo', 'Máximo'], $groups->map(fn ($g) => [$g->id, $g->product_id, $products[$g->product_id]->name, $g->name, $g->kind, $g->minimum, $g->maximum]));
        $add('Adicionais', ['ID adicional', 'ID grupo', 'ID produto', 'Produto', 'Grupo', 'Opção', 'Preço R$', 'Habilitado'], $options->map(function ($o) use ($groups, $products, $yes): array {
            $group = $groups[$o->option_group_id];

            return [$o->id, $group->id, $group->product_id, $products[$group->product_id]->name, $group->name, $o->name, $o->price_cents / 100, $yes($o->available)];
        }), [7]);
        $add('Sabores', ['ID sabor', 'ID produto', 'Produto', 'Sabor', 'Descrição', 'Habilitado'], $flavors->map(fn ($f) => [$f->id, $f->product_id, $products[$f->product_id]->name, $f->name, $f->description, $yes($f->available)]));
        $prices = [];
        foreach ($flavors as $flavor) {
            foreach (json_decode($flavor->prices, true) ?? [] as $variantId => $cents) {
                $variant = $variants->get($variantId);
                if (! $variant || $variant->product_id !== $flavor->product_id) {
                    continue;
                }
                $prices[] = [$flavor->id, $flavor->product_id, $variant->id, $variant->name, is_numeric($cents) ? $cents / 100 : null];
                abort_if(count($prices) > 5000, 422, 'A tabela de preços dos sabores excede o limite de exportação.');
            }
        }
        $add('Precos sabores', ['ID sabor', 'ID produto', 'ID variação', 'Variação', 'Preço R$'], $prices, [5]);
        $add('Grupos combos', ['ID grupo combo', 'ID produto', 'Combo', 'Grupo', 'Mínimo', 'Máximo'], $comboGroups->map(fn ($g) => [$g->id, $g->product_id, $products[$g->product_id]->name, $g->name, $g->minimum, $g->maximum]));
        $add('Itens combos', ['ID opção combo', 'ID grupo combo', 'ID combo', 'Combo', 'Grupo', 'ID componente', 'Componente', 'ID variação', 'Variação', 'Quantidade', 'Acréscimo unitário R$', 'Habilitado', 'Descrição livre', 'Referência'], $comboOptions->map(function ($o) use ($comboGroups, $products, $variants, $yes): array {
            $group = $comboGroups[$o->combo_group_id];
            $product = $products->get($o->component_product_id);
            $variant = $variants->get($o->component_variant_id);
            $valid = (! $o->component_product_id || $product) && (! $o->component_variant_id || ($product && $variant && $variant->product_id === $product->id));

            return [$o->id, $group->id, $group->product_id, $products[$group->product_id]->name, $group->name, $valid ? $o->component_product_id : null, $valid ? $product?->name : null, $valid ? $o->component_variant_id : null, $valid ? $variant?->name : null, $o->quantity, $o->surcharge_cents / 100, $yes($o->available), $o->label, $valid ? 'Válida' : 'Componente fora da loja ou referência inválida'];
        }), [11]);

        return $tables;
    }

    public function write(array $tables, string $path): void
    {
        $book = new Spreadsheet;
        try {
            $book->getProperties()->setCreator('CadapioWeb')->setTitle('Catálogo da loja');
            foreach ($tables as $index => $table) {
                $sheet = $index === 0 ? $book->getActiveSheet() : $book->createSheet();
                $sheet->setTitle($table['name']);
                foreach ([$table['headers'], ...$table['rows']] as $rowIndex => $row) {
                    foreach ($row as $columnIndex => $value) {
                        $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], $value ?? '', is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    }
                }
                $last = Coordinate::stringFromColumnIndex(count($table['headers']));
                $height = count($table['rows']) + 1;
                $sheet->freezePane('A2')->setAutoFilter('A1:'.$last.$height);
                $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                $sheet->getStyle('A1:'.$last.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF623F');
                $sheet->getDefaultColumnDimension()->setWidth(22);
                $sheet->getRowDimension(1)->setRowHeight(30);
                $sheet->getStyle('A1:'.$last.'1')->getAlignment()->setWrapText(true);
                foreach ($table['headers'] as $column => $header) {
                    $letter = Coordinate::stringFromColumnIndex($column + 1);
                    if (str_starts_with($header, 'ID ')) {
                        $sheet->getColumnDimension($letter)->setWidth(14);
                    } elseif (str_contains($header, 'Descrição') || str_contains($header, 'Link')) {
                        $sheet->getColumnDimension($letter)->setWidth(48);
                        $sheet->getStyle($letter.'1:'.$letter.$height)->getAlignment()->setWrapText(true);
                    } else {
                        $sheet->getColumnDimension($letter)->setWidth(28);
                    }
                }
                if ($table['name'] === 'Instrucoes') {
                    $sheet->getColumnDimension('B')->setWidth(100);
                    $sheet->getStyle('B1:B'.$height)->getAlignment()->setWrapText(true);
                }
                foreach ($table['money'] as $column) {
                    $letter = Coordinate::stringFromColumnIndex($column);
                    $sheet->getStyle($letter.'2:'.$letter.max(2, $height))->getNumberFormat()->setFormatCode('#,##0.00');
                }
            }
            $book->setActiveSheetIndex(0);
            (new Xlsx($book))->setPreCalculateFormulas(false)->save($path);
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
