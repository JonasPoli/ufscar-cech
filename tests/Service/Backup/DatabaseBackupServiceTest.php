<?php

namespace App\Tests\Service\Backup;

use App\Service\Backup\DatabaseBackupService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DatabaseBackupServiceTest extends KernelTestCase
{
    private DatabaseBackupService $backupService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->backupService = static::getContainer()->get(DatabaseBackupService::class);
    }

    public function testGetDatabaseOverview(): void
    {
        $overview = $this->backupService->getDatabaseOverview();

        $this->assertArrayHasKey('database', $overview);
        $this->assertArrayHasKey('tableCount', $overview);
        $this->assertArrayHasKey('totalRows', $overview);
        $this->assertArrayHasKey('tables', $overview);
        $this->assertGreaterThan(0, $overview['tableCount']);
        $this->assertNotEmpty($overview['database']);
    }

    public function testExportDatabaseAndListBackups(): void
    {
        $customName = 'test_backup_' . uniqid();
        $result = $this->backupService->exportDatabase(true, null, $customName);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['isZip']);
        $this->assertFileExists($result['filePath']);
        $this->assertStringEndsWith('.zip', $result['filename']);
        $this->assertGreaterThan(0, $result['fileSize']);

        // Check if listed
        $backups = $this->backupService->listBackups();
        $found = false;
        foreach ($backups as $b) {
            if ($b['filename'] === $result['filename']) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'O backup gerado deve estar na listagem');

        // Test get file
        $fileInfo = $this->backupService->getBackupFile($result['filename']);
        $this->assertNotNull($fileInfo);
        $this->assertSame($result['filename'], $fileInfo->getFilename());

        // Test delete
        $deleted = $this->backupService->deleteBackup($result['filename']);
        $this->assertTrue($deleted);
        $this->assertFileDoesNotExist($result['filePath']);
    }

    public function testImportDatabase(): void
    {
        $customName = 'test_import_backup_' . uniqid();
        $exportResult = $this->backupService->exportDatabase(true, null, $customName);
        $this->assertTrue($exportResult['success']);

        $importResult = $this->backupService->importDatabase($exportResult['filePath']);
        $this->assertTrue($importResult['success']);
        $this->assertGreaterThan(0, $importResult['durationSec']);

        // Cleanup
        $this->backupService->deleteBackup($exportResult['filename']);
    }
}

