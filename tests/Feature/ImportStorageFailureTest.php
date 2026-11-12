<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InstitutionalWorkbook;
use App\Services\StudentImportService;
use App\Services\UserManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use Tests\TestCase;

class ImportStorageFailureTest extends TestCase
{
    private function assertStorageFailureBecomesValidationError(\Throwable $failure): void
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('fresh')->andReturnSelf();
        $actor->shouldReceive('hasRole')->with('admin')->andReturnTrue();
        $period = new AcademicPeriod;
        $period->status = 'active';
        $service = Mockery::mock(StudentImportService::class, [app(InstitutionalWorkbook::class), app(UserManager::class), app(AuditLogger::class)])->makePartial();
        $service->shouldReceive('inspect')->once()->andReturn([]);
        $upload = Mockery::mock(UploadedFile::class)->makePartial();
        $upload->shouldReceive('getRealPath')->andReturn('/not-read-in-this-test.xlsx');
        $upload->shouldReceive('storeAs')->once()->andThrow($failure);
        try {
            $service->preview($actor, $period, $upload);
            $this->fail('Se esperaba un error de validación del archivo.');
        } catch (ValidationException $e) {
            $message = $e->errors()['file'][0];
            $this->assertStringContainsString('almacenamiento privado', $message);
            $this->assertStringNotContainsString('/private-path', $message);
        }
    }

    public function test_directory_permission_failure_returns_safe_file_validation_error(): void
    {
        $this->assertStorageFailureBecomesValidationError(UnableToCreateDirectory::atLocation('/private-path'));
    }

    public function test_file_write_failure_returns_safe_file_validation_error(): void
    {
        $this->assertStorageFailureBecomesValidationError(UnableToWriteFile::atLocation('/private-path'));
    }
}
