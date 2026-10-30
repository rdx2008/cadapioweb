<?php

namespace App\Services;

use Illuminate\Support\Str;

class CatalogImportPlan
{
    public const FIELDS = ['name' => 'Produto / nome', 'category' => 'Categoria', 'description' => 'Descrição', 'price' => 'Preço em reais', 'variant' => 'Tamanho / variante', 'type' => 'Tipo do produto', 'option' => 'Opção / sabor', 'group' => 'Grupo de escolhas', 'required' => 'Obrigatório?', 'selection' => 'Regra / limite de escolhas', 'pricing' => 'Tipo de valor', 'photo' => 'Link da foto', 'featured' => 'Também listado em / destaque'];

    private const ALIASES = [
        'name' => ['produto', 'nome', 'nome do produto', 'product', 'product name', 'item', 'sabor'],
        'category' => ['categoria', 'category', 'departamento', 'secao'], 'description' => ['descricao', 'description', 'detalhes', 'ingredientes'],
        'price' => ['preco', 'preco r', 'valor', 'valor r', 'price', 'preco de venda', 'preco venda', 'valor venda', 'valor unitario'],
        'variant' => ['tamanho', 'variante', 'nome da variacao', 'variant', 'size'], 'type' => ['tipo', 'tipo de produto', 'product type'],
        'option' => ['variacao opcao', 'opcao', 'variacao', 'option', 'nome da opcao'], 'group' => ['grupo de opcoes', 'grupo', 'option group', 'grupo de adicionais'],
        'required' => ['obrigatorio', 'required'], 'selection' => ['regra de selecao', 'limite', 'maximum', 'maximo'],
        'pricing' => ['tipo de valor', 'tipo de preco', 'pricing'], 'photo' => ['link da foto', 'foto', 'imagem', 'photo', 'image', 'url da imagem'],
        'featured' => ['tambem listado em', 'destaque', 'featured'],
    ];

    public function normalize(mixed $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower((string) $value))));
    }

    public function detect(array $sheets): array
    {
        $mapping = [];
        foreach ($sheets as $index => $sheet) {
            $best = ['header' => 1, 'columns' => [], 'role' => 'ignore'];
            $score = 0;
            foreach (array_slice($sheet['rows'], 0, 20) as $rowIndex => $row) {
                $columns = [];
                foreach ($row as $column => $value) {
                    $normalized = $this->normalize($value);
                    foreach (self::ALIASES as $field => $aliases) {
                        if ($normalized !== '' && in_array($normalized, $aliases, true) && ! isset($columns[$field])) {
                            $columns[$field] = $column;
                            break;
                        }
                    }
                }
                if (count($columns) > $score) {
                    $score = count($columns);
                    $best = ['header' => $rowIndex + 1, 'columns' => $columns, 'role' => isset($columns['name']) ? (isset($columns['option']) ? 'options' : 'products') : 'ignore'];
                }
            }
            if (in_array($this->normalize($sheet['name']), ['notas', 'notes', 'instrucoes', 'readme'], true) || ($this->normalize($sheet['name']) === 'pizzas por sabor' && count($sheets) > 1)) {
                $best['role'] = 'ignore';
            }
            $mapping[$index] = $best;
        }

        return $mapping;
    }

    public function money(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) && $value >= 0 && $value <= 10000 ? (int) round($value * 100) : null;
        }
        $value = trim(str_replace(['R$', ' ', "\u{00A0}"], '', (string) $value));
        if (preg_match('/^\d{1,3}(?:\.\d{3})+,\d{1,2}$/D', $value)) {
            $value = str_replace('.', '', $value);
        }
        if (! preg_match('/^\d{1,5}(?:[.,]\d{1,2})?$/D', $value)) {
            return null;
        }
        $parts = preg_split('/[.,]/', $value);
        $cents = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');

        return $cents <= 1000000 ? $cents : null;
    }

    public function build(array $sheets, array $mapping): array
    {
        $products = [];
        $errors = [];
        $warnings = [];
        $options = [];
        $ignored = [];
        foreach ($sheets as $sheetIndex => $sheet) {
            $map = $mapping[$sheetIndex] ?? ['role' => 'ignore', 'header' => 1, 'columns' => []];
            if ($map['role'] === 'ignore') {
                $ignored[] = $sheet['name'];

                continue;
            }
            $columns = array_filter($map['columns'] ?? [], fn ($v) => $v !== '' && $v !== null);
            $header = (int) $map['header'];
            if (! isset($columns['name'])) {
                $errors[] = $sheet['name'].': selecione a coluna do produto.';

                continue;
            }
            $priceColumns = [];
            foreach ($sheet['rows'][$header - 1] ?? [] as $c => $label) {
                if (preg_match('/(?:\d+\s*cm|^(?:p|m|g|gg|pequeno|medio|grande)(?:\s|$))/i', (string) $label)) {
                    $priceColumns[$c] = trim((string) $label);
                }
            }
            foreach (array_slice($sheet['rows'], $header, null, true) as $rowIndex => $row) {
                if (! count(array_filter($row, fn ($v) => $v !== null && $v !== ''))) {
                    continue;
                }
                $get = fn ($field) => isset($columns[$field]) ? ($row[(int) $columns[$field]] ?? '') : '';
                $name = trim((string) $get('name'));
                $category = trim((string) $get('category')) ?: 'Importados';
                $source = $sheet['name'].' · linha '.($rowIndex + 1);
                if ($name === '') {
                    $errors[] = $source.': produto sem nome.';

                    continue;
                }
                if (mb_strlen($name) > 120 || mb_strlen($category) > 80) {
                    $errors[] = $source.': nome ou categoria excede o limite (120/80 caracteres).';

                    continue;
                }
                if (str_starts_with($name, '=')) {
                    $errors[] = $source.': substitua fórmulas por valores.';

                    continue;
                }
                if ($map['role'] === 'options') {
                    $options[] = ['name' => $name, 'category' => trim((string) $get('category')), 'group' => (string) $get('group'), 'option' => (string) $get('option'), 'price' => $get('price'), 'pricing' => (string) $get('pricing'), 'required' => $get('required'), 'selection' => (string) $get('selection'), 'source' => $source];

                    continue;
                }
                $key = $this->normalize($category).'|'.$this->normalize($name);
                $variant = trim((string) $get('variant'));
                $description = (string) $get('description');
                if (mb_strlen($description) > 1000) {
                    $errors[] = $source.': descrição maior que 1.000 caracteres; reduza antes de importar.';
                }
                $typeText = $this->normalize($get('type'));
                $type = match ($typeText) {
                    'pizza' => 'pizza','combo' => 'combo','common','comum','produto comum','' => 'common',default => 'invalid'
                };
                if ($type === 'invalid') {
                    $errors[] = $source.': tipo de produto não reconhecido.';
                }
                if ($typeText === '' && str_starts_with($this->normalize($name), 'combo')) {
                    $type = 'combo';
                }
                $price = $this->money($get('price'));
                if (! isset($products[$key])) {
                    $products[$key] = ['key' => $key, 'name' => $name, 'category' => $category, 'description' => $description, 'price_cents' => $price, 'type' => $type, 'variants' => [], 'flavors' => [], 'groups' => [], 'combo_groups' => [], 'photo' => (string) $get('photo'), 'featured' => str_contains($this->normalize($get('featured')), 'destaque'), 'source' => $source];
                } elseif (! $variant && ! $priceColumns) {
                    $errors[] = $source.': produto repetido na mesma categoria. Corrija ou informe a coluna de tamanho.';

                    continue;
                }
                if ($variant !== '') {
                    $this->variant($products[$key], $variant, $price, $source, $errors);
                } elseif ($price === null && $priceColumns) {
                    foreach ($priceColumns as $column => $size) {
                        $value = $row[$column] ?? '';
                        if ($value !== '' && $value !== null) {
                            $this->variant($products[$key], $size, $this->money($value), $source, $errors);
                        }
                    }
                } elseif ($price === null || $price <= 0) {
                    $errors[] = $source.': preço ausente ou inválido; informe um valor maior que zero.';
                }
            }
        }
        $names = [];
        foreach ($products as $key => $product) {
            $names[$this->normalize($product['name'])][] = $key;
        }
        foreach ($options as $option) {
            $name = $this->normalize($option['name']);
            $key = $this->normalize($option['category']).'|'.$name;
            $matches = $option['category'] === '' ? ($names[$name] ?? []) : (isset($products[$key]) ? [$key] : []);
            if (count($matches) !== 1) {
                $errors[] = $option['source'].': produto da opção não encontrado ou ambíguo.';

                continue;
            }
            $key = $matches[0];
            $product = &$products[$key];
            $label = trim($option['option']);
            $group = trim($option['group']) ?: 'Escolhas';
            if ($label === '' || mb_strlen($label) > 120 || mb_strlen($group) > 100) {
                $errors[] = $option['source'].': nome da opção/grupo ausente ou muito longo.';
                unset($product);

                continue;
            }
            $kind = $this->normalize($option['pricing']);
            $included = str_contains($kind, 'incluso');
            $flavor = str_contains($kind, 'preco da pizza') || str_contains($kind, 'sabor da pizza');
            if ($kind !== '' && ! $included && ! $flavor && ! preg_match('/adicional|acrescimo|extra|surcharge/', $kind)) {
                $errors[] = $option['source'].': tipo de cobrança não reconhecido. Informe incluso, adicional ou preço da pizza com este sabor.';
                unset($product);

                continue;
            }
            $price = $this->money($option['price']);
            if ($included && ($option['price'] === '' || $option['price'] === null)) {
                $price = 0;
            }
            if ($price === null || $price > 100000 || ($flavor && $price <= 0)) {
                $errors[] = $option['source'].': valor da opção indefinido; zero só é automático para itens explicitamente inclusos.';
                unset($product);

                continue;
            }
            $limits = $this->limits($option['selection'], $option['required']);
            if ($limits === null) {
                $errors[] = $option['source'].': regra de escolha não reconhecida (use “Escolha de 1 a 2 opções”, “Escolha até 3 opções” ou um máximo numérico).';
                unset($product);

                continue;
            }
            [$minimum,$maximum] = $limits;
            if ($flavor) {
                $product['type'] = 'pizza';
                if ($maximum > 4 || $minimum !== 1 || mb_strlen($label) > 80) {
                    $errors[] = $option['source'].': sabores exigem mínimo 1, máximo 4 e nome de até 80 caracteres.';
                }
                $product['pizza_max'] = $maximum;
                $product['flavors'][] = ['name' => $label, 'price_cents' => $price];
            } else {
                $target = $included && $product['type'] === 'combo' ? 'combo_groups' : 'groups';
                if ($target === 'combo_groups' && $maximum > 10) {
                    $errors[] = $option['source'].': combo aceita até 10 escolhas por grupo.';
                }
                $gkey = $this->normalize($group);
                if (isset($product[$target][$gkey]) && ($product[$target][$gkey]['minimum'] !== $minimum || $product[$target][$gkey]['maximum'] !== $maximum)) {
                    $errors[] = $option['source'].': regras diferentes no mesmo grupo.';
                }
                $product[$target][$gkey] ??= ['name' => $group, 'minimum' => $minimum, 'maximum' => $maximum, 'options' => []];
                if (collect($product[$target][$gkey]['options'])->contains('name', $label)) {
                    $errors[] = $option['source'].': opção duplicada no grupo.';
                }
                $product[$target][$gkey]['options'][] = ['name' => $label, 'price_cents' => $price];
            }
            unset($product);
        }
        foreach ($products as &$product) {
            if ($product['flavors'] && $product['variants']) {
                $errors[] = $product['source'].': sabores com vários tamanhos precisam de um produto por tamanho nesta importação.';
            }
            if (collect($product['flavors'])->pluck('name')->map(fn ($name) => $this->normalize($name))->duplicates()->isNotEmpty()) {
                $errors[] = $product['source'].': há sabores repetidos.';
            }
            foreach (array_merge($product['groups'], $product['combo_groups']) as $group) {
                if ($group['maximum'] > count($group['options'])) {
                    $warnings[] = $product['name'].' · '.$group['name'].': cada opção pode ser escolhida uma vez; repetição da mesma opção não é suportada.';
                }
                if (count($group['options']) < $group['minimum']) {
                    $errors[] = $product['source'].': grupo sem opções suficientes para o mínimo.';
                }
            }
            if (count($product['groups']) > 20 || count($product['variants']) > 20 || count($product['flavors']) > 100 || count($product['combo_groups']) > 10) {
                $errors[] = $product['source'].': ultrapassa o limite de tamanhos, sabores ou grupos de combo.';
            }
            if ($product['type'] === 'pizza' && ! $product['flavors']) {
                $errors[] = $product['source'].': pizza sem sabores/preços. Mapeie a aba de opções ou escolha Produto comum no arquivo.';
            }
            if ($product['type'] === 'combo' && $product['variants']) {
                $errors[] = $product['source'].': combo não pode ter tamanhos.';
            }
            if ($product['photo'] !== '' && ! preg_match('~^https://storage\.googleapis\.com/[^\s?#]+$~D', $product['photo'])) {
                $warnings[] = $product['name'].': foto de origem não compatível; adicione a imagem no editor.';
            }
            if ($product['flavors']) {
                $warnings[] = $product['name'].': sabores importados com cobrança pelo maior preço; confira antes de habilitar.';
            }
        }unset($product);
        if (! $products) {
            $errors[] = 'Nenhum produto encontrado. Confira as abas, o cabeçalho e as colunas selecionadas.';
        }

        return ['products' => array_values($products), 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)), 'ignored' => $ignored, 'option_count' => count($options)];
    }

    private function variant(array &$product, string $name, ?int $price, string $source, array &$errors): void
    {
        if ($price === null || $price <= 0 || mb_strlen($name) > 80 || isset($product['variants'][$this->normalize($name)])) {
            $errors[] = $source.': tamanho repetido, nome muito longo ou preço inválido.';

            return;
        }
        $product['variants'][$this->normalize($name)] = ['name' => $name, 'price_cents' => $price];
    }

    private function limits(string $selection, mixed $required): ?array
    {
        $text = $this->normalize($selection);
        $minimum = in_array($this->normalize($required), ['sim', 'yes', '1', 'true', 'obrigatorio'], true) ? 1 : 0;
        if (preg_match('/(?:de )?(\d+) a (\d+)/', $text, $m)) {
            $minimum = (int) $m[1];
            $maximum = (int) $m[2];
        } elseif (preg_match('/(?:ate |escolha )?(\d+)/', $text, $m)) {
            $maximum = (int) $m[1];
        } else {
            return null;
        }

        return $maximum >= 1 && $maximum <= 99 && $minimum <= $maximum ? [$minimum, $maximum] : null;
    }
}
