<?php

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

class DatabaseBackupService
{
    private string $backupDir;
    private Filesystem $filesystem;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%/var/backups')]
        string $backupDir
    ) {
        $this->backupDir = rtrim($backupDir, '/\\');
        $this->filesystem = new Filesystem();
        $this->ensureBackupDirExists();
    }

    public function getBackupDir(): string
    {
        return $this->backupDir;
    }

    private function ensureBackupDirExists(): void
    {
        if (!$this->filesystem->exists($this->backupDir)) {
            $this->filesystem->mkdir($this->backupDir, 0775);
            $gitignorePath = $this->backupDir . '/.gitignore';
            if (!$this->filesystem->exists($gitignorePath)) {
                $this->filesystem->dumpFile($gitignorePath, "*\n!.gitignore\n");
            }
        }
    }

    /**
     * Obtains overview stats of the current database.
     */
    public function getDatabaseOverview(): array
    {
        $dbName = $this->connection->getDatabase();

        $sql = "SELECT 
                    table_name AS `name`,
                    engine AS `engine`,
                    table_rows AS `rows`,
                    data_length AS `data_bytes`,
                    index_length AS `index_bytes`,
                    (data_length + index_length) AS `total_bytes`,
                    table_collation AS `collation`,
                    table_comment AS `comment`
                FROM information_schema.tables 
                WHERE table_schema = :dbName 
                ORDER BY (data_length + index_length) DESC";

        $tables = $this->connection->fetchAllAssociative($sql, ['dbName' => $dbName]);

        $totalDataBytes = 0;
        $totalIndexBytes = 0;
        $totalRows = 0;

        foreach ($tables as $t) {
            $totalDataBytes += (int)$t['data_bytes'];
            $totalIndexBytes += (int)$t['index_bytes'];
            $totalRows += (int)$t['rows'];
        }

        $serverVersion = $this->connection->fetchOne("SELECT VERSION()") ?: 'MySQL';

        return [
            'database' => $dbName,
            'serverVersion' => $serverVersion,
            'tableCount' => count($tables),
            'totalRows' => $totalRows,
            'dataBytes' => $totalDataBytes,
            'indexBytes' => $totalIndexBytes,
            'totalBytes' => $totalDataBytes + $totalIndexBytes,
            'dataSizeFormatted' => $this->formatBytes($totalDataBytes),
            'indexSizeFormatted' => $this->formatBytes($totalIndexBytes),
            'totalSizeFormatted' => $this->formatBytes($totalDataBytes + $totalIndexBytes),
            'tables' => array_map(function($t) {
                $bytes = (int)$t['total_bytes'];
                return [
                    'name' => $t['name'],
                    'engine' => $t['engine'] ?: 'InnoDB',
                    'rows' => (int)$t['rows'],
                    'totalBytes' => $bytes,
                    'sizeFormatted' => $this->formatBytes($bytes),
                ];
            }, $tables),
        ];
    }

    /**
     * Lists existing backup files.
     */
    public function listBackups(): array
    {
        $this->ensureBackupDirExists();
        $files = glob($this->backupDir . '/*.{zip,gz,sql}', GLOB_BRACE) ?: [];
        $backups = [];

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $size = filesize($filePath) ?: 0;
            $mtime = filemtime($filePath) ?: time();

            $backups[] = [
                'filename' => $filename,
                'path' => $filePath,
                'size' => $size,
                'sizeFormatted' => $this->formatBytes($size),
                'createdAt' => (new \DateTimeImmutable())->setTimestamp($mtime),
                'isZip' => str_ends_with($filename, '.zip'),
            ];
        }

        usort($backups, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);

        return $backups;
    }

    /**
     * Returns a specific backup file info if valid.
     */
    public function getBackupFile(string $filename): ?\SplFileInfo
    {
        $cleanName = basename($filename);
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.(zip|gz|sql)$/', $cleanName)) {
            return null;
        }

        $fullPath = $this->backupDir . '/' . $cleanName;
        if (!file_exists($fullPath) || !is_file($fullPath)) {
            return null;
        }

        return new \SplFileInfo($fullPath);
    }

    /**
     * Safely deletes a backup file.
     */
    public function deleteBackup(string $filename): bool
    {
        $file = $this->getBackupFile($filename);
        if (!$file) {
            return false;
        }

        return unlink($file->getRealPath());
    }

    /**
     * Performs a complete database dump and optional zip compression.
     *
     * @param bool $useZip If true, compresses into a .zip file.
     * @param callable|null $progressCallback Callback: fn(string $status, string $currentTable, int $tableIndex, int $totalTables, int $percent, int $processedRows, int $totalRows)
     * @param string|null $customFilename Custom base filename (without extension).
     * @return array Metadata about the generated dump.
     */
    public function exportDatabase(
        bool $useZip = true,
        ?callable $progressCallback = null,
        ?string $customFilename = null
    ): array {
        $startTime = microtime(true);
        $this->ensureBackupDirExists();

        $dbOverview = $this->getDatabaseOverview();
        $dbName = $dbOverview['database'];
        $tables = $dbOverview['tables'];
        $totalTables = count($tables);
        $totalEstimatedRows = max(1, $dbOverview['totalRows']);

        $dateSuffix = date('Y-m-d_H-i-s');
        $baseName = $customFilename ?: sprintf('backup_%s_%s', preg_replace('/[^a-zA-Z0-9_]/', '_', $dbName), $dateSuffix);
        $sqlFilePath = $this->backupDir . '/' . $baseName . '.sql';

        $handle = fopen($sqlFilePath, 'w');
        if (!$handle) {
            throw new \RuntimeException("Não foi possível criar o arquivo de dump em: {$sqlFilePath}");
        }

        // Header SQL
        fwrite($handle, "-- ====================================================================\n");
        fwrite($handle, "-- UFSCar CECH - Complete Database Superdump\n");
        fwrite($handle, "-- Database: {$dbName}\n");
        fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Server Version: {$dbOverview['serverVersion']}\n");
        fwrite($handle, "-- Total Tables: {$totalTables}\n");
        fwrite($handle, "-- ====================================================================\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n");
        fwrite($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
        fwrite($handle, "SET AUTOCOMMIT = 0;\n");
        fwrite($handle, "START TRANSACTION;\n\n");

        if ($progressCallback) {
            $progressCallback('starting', '', 0, $totalTables, 0, 0, $totalEstimatedRows);
        }

        $processedRowsAccum = 0;

        // Dump each table
        foreach ($tables as $index => $tableInfo) {
            $tableName = $tableInfo['name'];
            $tableNum = $index + 1;

            if ($progressCallback) {
                $percent = (int)round(($index / $totalTables) * 90);
                $progressCallback('dumping_table', $tableName, $tableNum, $totalTables, $percent, $processedRowsAccum, $totalEstimatedRows);
            }

            fwrite($handle, "-- --------------------------------------------------------------------\n");
            fwrite($handle, "-- Table structure and data for table `{$tableName}`\n");
            fwrite($handle, "-- --------------------------------------------------------------------\n\n");

            // DROP TABLE
            fwrite($handle, "DROP TABLE IF EXISTS `{$tableName}`;\n");

            // CREATE TABLE
            $createTableStmt = $this->connection->fetchAssociative("SHOW CREATE TABLE `{$tableName}`");
            if ($createTableStmt && isset($createTableStmt['Create Table'])) {
                fwrite($handle, $createTableStmt['Create Table'] . ";\n\n");
            } elseif ($createTableStmt && isset($createTableStmt['Create View'])) {
                fwrite($handle, $createTableStmt['Create View'] . ";\n\n");
                continue;
            }

            // Dump data in chunks of 500
            $chunkSize = 500;
            $offset = 0;

            // Get column names
            $columnsStmt = $this->connection->fetchAllAssociative("SHOW COLUMNS FROM `{$tableName}`");
            $columnNames = array_map(fn($col) => '`' . $col['Field'] . '`', $columnsStmt);
            $columnsSql = implode(', ', $columnNames);

            while (true) {
                $rows = $this->connection->fetchAllAssociative(
                    "SELECT * FROM `{$tableName}` LIMIT {$chunkSize} OFFSET {$offset}"
                );

                if (empty($rows)) {
                    break;
                }

                $insertLines = [];
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $values[] = 'NULL';
                        } elseif (is_int($val) || is_float($val)) {
                            $values[] = (string)$val;
                        } else {
                            $values[] = $this->connection->quote((string)$val);
                        }
                    }
                    $insertLines[] = '(' . implode(', ', $values) . ')';
                }

                if (!empty($insertLines)) {
                    fwrite($handle, "INSERT INTO `{$tableName}` ({$columnsSql}) VALUES\n");
                    fwrite($handle, implode(",\n", $insertLines) . ";\n");
                }

                $count = count($rows);
                $offset += $count;
                $processedRowsAccum += $count;

                if ($count < $chunkSize) {
                    break;
                }
            }

            fwrite($handle, "\n");
        }

        // Footer
        fwrite($handle, "-- --------------------------------------------------------------------\n");
        fwrite($handle, "COMMIT;\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fwrite($handle, "-- Dump completed at " . date('Y-m-d H:i:s') . "\n");

        fclose($handle);

        $sqlSize = filesize($sqlFilePath) ?: 0;
        $finalPath = $sqlFilePath;
        $finalFilename = basename($sqlFilePath);
        $finalSize = $sqlSize;

        // Zip compression
        if ($useZip) {
            if ($progressCallback) {
                $progressCallback('compressing', '', $totalTables, $totalTables, 95, $processedRowsAccum, $totalEstimatedRows);
            }

            $zipFilePath = $this->backupDir . '/' . $baseName . '.sql.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                $zip->addFile($sqlFilePath, $baseName . '.sql');
                $zip->close();

                // Remove uncompressed SQL file
                @unlink($sqlFilePath);

                $finalPath = $zipFilePath;
                $finalFilename = basename($zipFilePath);
                $finalSize = filesize($zipFilePath) ?: 0;
            }
        }

        $duration = round(microtime(true) - $startTime, 2);

        if ($progressCallback) {
            $progressCallback('completed', '', $totalTables, $totalTables, 100, $processedRowsAccum, $totalEstimatedRows);
        }

        return [
            'success' => true,
            'filename' => $finalFilename,
            'filePath' => $finalPath,
            'fileSize' => $finalSize,
            'fileSizeFormatted' => $this->formatBytes($finalSize),
            'sqlSize' => $sqlSize,
            'sqlSizeFormatted' => $this->formatBytes($sqlSize),
            'isZip' => $useZip,
            'tableCount' => $totalTables,
            'rowCount' => $processedRowsAccum,
            'durationSec' => $duration,
            'createdAt' => new \DateTimeImmutable(),
        ];
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
