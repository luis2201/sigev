<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class InstitutionalWorkbook
{
    public const HEADERS = ['identification_type', 'identification_number', 'name', 'email', 'career_code'];

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    private function xml(string $value): DOMDocument
    {
        if (stripos($value, '<!DOCTYPE') !== false || stripos($value, '<!ENTITY') !== false) {
            $this->fail('El archivo contiene declaraciones XML no permitidas.');
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($value, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $ok) {
            $this->fail('El contenido del libro no es válido.');
        }

        return $doc;
    }

    /** @return array<int, array{number:int,data:array<string,string>,errors:array<string,string>}> */
    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->fail('Seleccione un libro Excel .xlsx válido.');
        }
        try {
            $size = 0;
            if ($zip->numFiles > 2000) {
                $this->fail('El libro excede los límites permitidos.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $size += $stat['size'];
                if ($size > config('academic.import_max_uncompressed_bytes') || preg_match('~vbaProject|externalLinks/|\.bin$~i', $stat['name'])) {
                    $this->fail('El libro excede el tamaño permitido o contiene elementos no admitidos.');
                }
            }
            $workbook = $zip->getFromName('xl/workbook.xml');
            $relations = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($workbook === false || $relations === false || $zip->locateName('[Content_Types].xml') === false) {
                $this->fail('El contenido no corresponde a un libro .xlsx.');
            }
            $wb = new DOMXPath($this->xml($workbook));
            $sheets = $wb->query('//*[local-name()="sheets"]/*[local-name()="sheet"]');
            if ($sheets->length !== 1) {
                $this->fail('Use un libro con una sola hoja de estudiantes.');
            }
            $relId = $sheets->item(0)->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $rels = new DOMXPath($this->xml($relations));
            $sheetPath = null;
            foreach ($rels->query('//*[local-name()="Relationship"]') as $rel) {
                if ($rel->getAttribute('TargetMode') === 'External') {
                    $this->fail('No se admiten relaciones externas.');
                }
                if ($rel->getAttribute('Id') === $relId) {
                    $target = $rel->getAttribute('Target');
                    $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                }
            }
            if (! $sheetPath || str_contains($sheetPath, '..') || ($sheet = $zip->getFromName($sheetPath)) === false) {
                $this->fail('No se pudo localizar la hoja de estudiantes.');
            }
            $strings = [];
            if (($shared = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $xp = new DOMXPath($this->xml($shared));
                foreach ($xp->query('//*[local-name()="si"]') as $si) {
                    $value = '';
                    foreach ($xp->query('.//*[local-name()="t"]', $si) as $text) {
                        $value .= $text->textContent;
                    }
                    $strings[] = $value;
                }
            }
            $xp = new DOMXPath($this->xml($sheet));
            $xmlRows = $xp->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
            if ($xmlRows->length > config('academic.import_max_rows') + 1) {
                $this->fail('El libro supera el máximo de filas configurado.');
            }
            $result = [];
            $seenRows = [];
            foreach ($xmlRows as $position => $row) {
                $number = (int) $row->getAttribute('r');
                if ($number < 1 || isset($seenRows[$number])) {
                    $this->fail('El libro contiene números de fila inválidos o repetidos.');
                }
                $seenRows[$number] = true;
                $cells = [];
                $types = [];
                $errors = [];
                foreach ($xp->query('./*[local-name()="c"]', $row) as $cell) {
                    $ref = $cell->getAttribute('r');
                    if (! preg_match('/^([A-Z]+)'.$number.'$/', $ref, $match)) {
                        $this->fail('El libro contiene referencias de celdas inválidas.');
                    }
                    $column = $match[1];
                    if (isset($cells[$column])) {
                        $this->fail('El libro contiene celdas repetidas.');
                    }
                    $type = $cell->getAttribute('t');
                    $value = '';
                    if ($type === 'inlineStr') {
                        foreach ($xp->query('.//*[local-name()="t"]', $cell) as $text) {
                            $value .= $text->textContent;
                        }
                    } else {
                        $value = $xp->evaluate('string(./*[local-name()="v"])', $cell);
                        if ($type === 's') {
                            if (! ctype_digit($value) || ! array_key_exists((int) $value, $strings)) {
                                $this->fail('El libro contiene referencias de texto inválidas.');
                            }
                            $value = $strings[(int) $value];
                        }
                    }
                    $cells[$column] = trim($value);
                    $types[$column] = $type;
                    if ($xp->query('./*[local-name()="f"]', $cell)->length) {
                        $errors[self::HEADERS[ord($column[0]) - 65] ?? 'file'] = 'formula_not_allowed';
                    }
                    if (strlen($value) > 4000) {
                        $this->fail('Una celda excede el tamaño permitido.');
                    }
                    if (! in_array($column, ['A', 'B', 'C', 'D', 'E'], true) && trim($value) !== '') {
                        $this->fail('La hoja contiene columnas adicionales; utilice la plantilla institucional.');
                    }
                }
                if ($position === 0) {
                    if ($number !== 1 || array_map(fn ($col) => $cells[$col] ?? '', ['A', 'B', 'C', 'D', 'E']) !== self::HEADERS || $errors) {
                        $this->fail('Los encabezados deben coincidir con la plantilla institucional.');
                    }

                    continue;
                }
                if (! array_filter($cells, fn ($v) => $v !== '')) {
                    continue;
                }
                if (! in_array($types['B'] ?? '', ['s', 'inlineStr'], true)) {
                    $errors['identification_number'] = 'identification_must_be_text';
                }
                $data = array_combine(self::HEADERS, array_map(fn ($col) => $cells[$col] ?? '', ['A', 'B', 'C', 'D', 'E']));
                $data['email'] = mb_strtolower($data['email']);
                $result[] = ['number' => $number, 'data' => $data, 'errors' => $errors];
            }
            if (! $result) {
                $this->fail('El libro no contiene estudiantes para importar.');
            }

            return $result;
        } finally {
            $zip->close();
        }
    }

    /** @param array<int,array<int,string>> $rows */
    public function write(string $path, array $rows = []): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo generar la plantilla.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Estudiantes" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="1"><xf numFmtId="49" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="5" width="30" customWidth="1" style="0"/></cols><sheetData>';
        foreach ([self::HEADERS, ...$rows] as $i => $values) {
            $sheet .= '<row r="'.($i + 1).'">';
            foreach ($values as $j => $value) {
                $sheet .= '<c r="'.chr(65 + $j).($i + 1).'" t="inlineStr" s="0"><is><t xml:space="preserve">'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet.'</sheetData></worksheet>');
        $zip->close();
    }
}
