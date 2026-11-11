<?php

namespace Tests\Feature;

use App\Services\InstitutionalWorkbook;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class WorkbookSecurityTest extends TestCase
{
    private array $files = [];

    private function workbook(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sigev-workbook-test-');
        $this->files[] = $path;
        app(InstitutionalWorkbook::class)->write($path, [['cedula', '0001234567', 'Estudiante', 'alumno@itsup.edu.ec', 'ENF']]);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_archive_with_invalid_headers_is_rejected(): void
    {
        $path = $this->workbook();
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = str_replace('career_code', 'unexpected_column', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $this->expectException(ValidationException::class);
        app(InstitutionalWorkbook::class)->read($path);
    }

    public function test_external_xml_entity_is_rejected_without_loading_it(): void
    {
        $path = $this->workbook();
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><!DOCTYPE sst [<!ENTITY unsafe SYSTEM "file:///nonexistent-sigev-fixture">]><sst>&unsafe;</sst>');
        $zip->close();
        $this->expectException(ValidationException::class);
        app(InstitutionalWorkbook::class)->read($path);
    }

    public function test_compressed_archive_cannot_exceed_expansion_limit(): void
    {
        $path = $this->workbook();
        config(['academic.import_max_uncompressed_bytes' => 100]);
        $this->expectException(ValidationException::class);
        app(InstitutionalWorkbook::class)->read($path);
    }

    public function test_book_with_multiple_worksheets_is_rejected(): void
    {
        $path = $this->workbook();
        $zip = new ZipArchive;
        $zip->open($path);
        $workbook = str_replace('</sheets>', '<sheet name="Otra hoja" sheetId="2" r:id="rId3"/></sheets>', $zip->getFromName('xl/workbook.xml'));
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->close();
        $this->expectException(ValidationException::class);
        app(InstitutionalWorkbook::class)->read($path);
    }
}
