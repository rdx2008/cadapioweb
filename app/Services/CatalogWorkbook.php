<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use ZipArchive;

class CatalogWorkbook
{
    public function read(string $path, string $extension): array
    {
        try {
            if ($extension === 'xlsx') {
                $zip = new ZipArchive;
                if ($zip->open($path) !== true) {
                    $this->fail('O arquivo Excel está inválido.');
                }
                $size = 0;
                if ($zip->numFiles > 2000) {
                    $zip->close();
                    $this->fail('O arquivo tem elementos demais. Exporte somente as tabelas do cardápio.');
                }
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $size += $zip->statIndex($i)['size'];
                }
                $zip->close();
                if ($size > 32 * 1024 * 1024) {
                    $this->fail('O conteúdo do arquivo é muito grande. Divida em arquivos menores.');
                }
            }
            $type = IOFactory::identify($path, ['Xls', 'Xlsx', 'Csv']);
            if (($extension === 'xlsx' && $type !== 'Xlsx') || ($extension === 'xls' && $type !== 'Xls') || ($extension === 'csv' && $type !== 'Csv')) {
                $this->fail('O conteúdo não corresponde ao formato do arquivo. Salve novamente como XLSX, XLS ou CSV.');
            }
            $reader = IOFactory::createReader($type);
            $reader->setReadDataOnly(true)->setReadEmptyCells(false);
            $reader->setAllowExternalImages(false);
            if ($type === 'Csv') {
                $reader->setInputEncoding('guess')->setFallbackEncoding('CP1252');
            }
            $info = $reader->listWorksheetInfo($path);
            if (array_sum(array_map(fn ($sheet) => $sheet['totalRows'] * $sheet['totalColumns'], $info)) > 100000) {
                $this->fail('Limite de 100.000 células por arquivo. Divida a planilha em partes menores.');
            }
            if (count($info) > 10 || array_sum(array_column($info, 'totalRows')) > 10000) {
                $this->fail('Limite de 10 abas e 10.000 linhas por arquivo.');
            }
            foreach ($info as $sheet) {
                if ($sheet['totalRows'] > 5000 || $sheet['totalColumns'] > 50) {
                    $this->fail('Cada aba pode ter até 5.000 linhas e 50 colunas.');
                }
            }
            $reader->setReadFilter(new class implements IReadFilter
            {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= 5000 && Coordinate::columnIndexFromString($columnAddress) <= 50;
                }
            });
            $book = $reader->load($path);
            $sheets = [];
            foreach ($book->getWorksheetIterator() as $sheet) {
                $rows = [];
                $columns = min(50, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
                for ($r = 1; $r <= $sheet->getHighestDataRow(); $r++) {
                    $row = [];
                    for ($c = 1; $c <= $columns; $c++) {
                        $cell = $sheet->getCell([$c, $r]);
                        $value = $cell->getValue();
                        if ($value instanceof RichText) {
                            $value = $value->getPlainText();
                        }
                        if (! is_scalar($value) && $value !== null) {
                            $value = '';
                        }
                        if (is_string($value) && mb_strlen($value) > 2000) {
                            $this->fail('Há uma célula com mais de 2.000 caracteres. Reduza o texto e envie novamente.');
                        }
                        $row[] = is_string($value) ? trim($value) : $value;
                    }
                    $rows[] = $row;
                }
                $sheets[] = ['name' => $sheet->getTitle(), 'rows' => $rows];
            }
            $book->disconnectWorksheets();

            return $sheets;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            $this->fail('Não foi possível ler a planilha. Confira o formato e remova senhas ou proteção do arquivo.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
