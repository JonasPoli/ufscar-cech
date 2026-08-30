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
                WHERE LOWER(table_schema) = LOWER(:dbName) 
                ORDER BY (data_length + index_length) DESC";

        try {
            $tables = $this->connection->fetchAllAssociative($sql, ['dbName' => $dbName]);
        } catch (\Throwable $e) {
            $tables = [];
        }

        if (empty($tables)) {
            // Fallback: list tables directly from database
            try {
                $rawTables = $this->connection->fetchFirstColumn("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
                $tables = [];
                foreach ($rawTables as $tableName) {
                    $count = (int)$this->connection->fetchOne("SELECT COUNT(*) FROM `{$tableName}`");
                    $tables[] = [
                        'name' => $tableName,
                        'engine' => 'InnoDB',
                        'rows' => $count,
                        'data_bytes' => 0,
                        'index_bytes' => 0,
                        'total_bytes' => 0,
                        'collation' => 'utf8mb4_unicode_ci',
                        'comment' => '',
                    ];
                }
            } catch (\Throwable $e) {
                $tables = [];
            }
        }

        $totalDataBytes = 0;
        $totalIndexBytes = 0;
        $totalRows = 0;

        foreach ($tables as $t) {
            $totalDataBytes += (int)($t['data_bytes'] ?? 0);
            $totalIndexBytes += (int)($t['index_bytes'] ?? 0);
            $totalRows += (int)($t['rows'] ?? 0);
        }

        $serverVersion = 'MySQL';
        try {
            $serverVersion = (string)($this->connection->fetchOne("SELECT VERSION()") ?: 'MySQL');
        } catch (\Throwable $e) {}

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
                $bytes = (int)($t['total_bytes'] ?? 0);
                return [
                    'name' => $t['name'],
                    'engine' => $t['engine'] ?? 'InnoDB',
                    'rows' => (int)($t['rows'] ?? 0),
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
        @\set_time_limit(0);
        @ini_set('memory_limit', '512M');

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

            // Get column names
            $columnsStmt = $this->connection->fetchAllAssociative("SHOW COLUMNS FROM `{$tableName}`");
            $columnNames = array_map(fn($col) => '`' . $col['Field'] . '`', $columnsStmt);
            $columnsSql = implode(', ', $columnNames);

            // Stream rows using cursor and batch inserts
            $stmt = $this->connection->executeQuery("SELECT * FROM `{$tableName}`");
            $insertLines = [];
            $batchSize = 500;
            $batchCount = 0;

            while (($row = $stmt->fetchAssociative()) !== false) {
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
                $batchCount++;
                $processedRowsAccum++;

                if ($batchCount >= $batchSize) {
                    fwrite($handle, "INSERT INTO `{$tableName}` ({$columnsSql}) VALUES\n");
                    fwrite($handle, implode(",\n", $insertLines) . ";\n\n");
                    $insertLines = [];
                    $batchCount = 0;
                }
            }

            if (!empty($insertLines)) {
                fwrite($handle, "INSERT INTO `{$tableName}` ({$columnsSql}) VALUES\n");
                fwrite($handle, implode(",\n", $insertLines) . ";\n\n");
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

    /**
     * Imports/restores a database dump from a .sql, .zip, or .gz file.
     *
     * @param string $sourceFilePath Path to the .sql, .zip, or .gz file.
     * @param callable|null $progressCallback fn(string $status, string $message, int $percent)
     * @return array Metadata about the restore.
     */
    public function importDatabase(
        string $sourceFilePath,
        ?callable $progressCallback = null
    ): array {
        $startTime = microtime(true);

        if (!file_exists($sourceFilePath) || !is_readable($sourceFilePath)) {
            throw new \InvalidArgumentException("Arquivo de backup não encontrado ou ilegível: {$sourceFilePath}");
        }

        $extension = strtolower(pathinfo($sourceFilePath, PATHINFO_EXTENSION));
        $tempExtractPath = null;
        $sqlPath = $sourceFilePath;

        if ($progressCallback) {
            $progressCallback('preparing', 'Processando arquivo de backup...', 5);
        }

        // Handle ZIP files
        if ($extension === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($sourceFilePath) !== true) {
                throw new \RuntimeException("Não foi possível abrir o arquivo ZIP: {$sourceFilePath}");
            }

            $sqlEntryName = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if (str_ends_with(strtolower($entryName), '.sql')) {
                    $sqlEntryName = $entryName;
                    break;
                }
            }

            if (!$sqlEntryName) {
                $zip->close();
                throw new \RuntimeException("Nenhum arquivo .sql encontrado dentro do pacote ZIP.");
            }

            $tempExtractPath = $this->backupDir . '/tmp_import_' . uniqid('', true) . '.sql';
            $extractedContent = $zip->getFromName($sqlEntryName);
            $zip->close();

            if ($extractedContent === false || file_put_contents($tempExtractPath, $extractedContent) === false) {
                throw new \RuntimeException("Falha ao extrair o arquivo .sql temporário do ZIP.");
            }

            $sqlPath = $tempExtractPath;
        } elseif ($extension === 'gz') {
            $tempExtractPath = $this->backupDir . '/tmp_import_' . uniqid('', true) . '.sql';
            $gz = gzopen($sourceFilePath, 'rb');
            if (!$gz) {
                throw new \RuntimeException("Não foi possível abrir o arquivo .gz.");
            }
            $out = fopen($tempExtractPath, 'wb');
            while (!gzeof($gz)) {
                fwrite($out, gzread($gz, 524288)); // 512KB chunks
            }
            gzclose($gz);
            fclose($out);

            $sqlPath = $tempExtractPath;
        }

        try {
            if ($progressCallback) {
                $progressCallback('importing', 'Executando script SQL no banco de dados...', 20);
            }

            $params = $this->connection->getParams();
            $dbName = $this->connection->getDatabase();
            $host = $params['host'] ?? '127.0.0.1';
            $port = (int)($params['port'] ?? 3306);
            $user = $params['user'] ?? 'root';
            $password = $params['password'] ?? '';

            // Try fast native mysql client if available
            $nativeExecuted = false;
            if (function_exists('proc_open') && !in_array('proc_open', explode(',', (string)ini_get('disable_functions')))) {
                $mysqlBin = 'mysql';
                $descriptors = [
                    0 => ['file', $sqlPath, 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w']
                ];

                $cmd = sprintf(
                    '%s -h %s -P %d -u %s %s %s',
                    escapeshellcmd($mysqlBin),
                    escapeshellarg($host),
                    $port,
                    escapeshellarg($user),
                    $password !== '' ? '-p' . escapeshellarg($password) : '',
                    escapeshellarg($dbName)
                );

                $process = @proc_open($cmd, $descriptors, $pipes, null, $_ENV);
                if (is_resource($process)) {
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $returnCode = proc_close($process);

                    if ($returnCode === 0) {
                        $nativeExecuted = true;
                    }
                }
            }

            $statementsCount = 0;
            if (!$nativeExecuted) {
                // Fallback: Streaming PHP SQL statement execution
                $handle = fopen($sqlPath, 'r');
                if (!$handle) {
                    throw new \RuntimeException("Não foi possível ler o arquivo SQL: {$sqlPath}");
                }

                $this->connection->executeStatement("SET FOREIGN_KEY_CHECKS = 0;");
                $this->connection->executeStatement("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");
                $this->connection->executeStatement("SET NAMES utf8mb4;");

                $currentQuery = '';
                $totalBytes = filesize($sqlPath) ?: 1;
                $bytesRead = 0;

                while (($line = fgets($handle)) !== false) {
                    $bytesRead += strlen($line);
                    $trimmed = trim($line);

                    if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                        continue;
                    }

                    if (str_starts_with($trimmed, '/*') && str_ends_with($trimmed, '*/;')) {
                        continue;
                    }

                    $currentQuery .= $line;

                    if (str_ends_with($trimmed, ';')) {
                        try {
                            $this->connection->executeStatement($currentQuery);
                            $statementsCount++;
                        } catch (\Throwable $e) {
                            // Continue on non-fatal statement errors
                        }
                        $currentQuery = '';

                        if ($progressCallback && $statementsCount % 50 === 0) {
                            $pct = min(90, (int)round(20 + (($bytesRead / $totalBytes) * 70)));
                            $progressCallback('importing', "Executados {$statementsCount} blocos SQL...", $pct);
                        }
                    }
                }

                if (trim($currentQuery) !== '') {
                    try {
                        $this->connection->executeStatement($currentQuery);
                        $statementsCount++;
                    } catch (\Throwable $e) {}
                }

                fclose($handle);
                $this->connection->executeStatement("SET FOREIGN_KEY_CHECKS = 1;");
            }

            if ($progressCallback) {
                $progressCallback('completed', 'Importação concluída com sucesso!', 100);
            }

            $duration = round(microtime(true) - $startTime, 2);

            return [
                'success' => true,
                'durationSec' => $duration,
                'nativeExecuted' => $nativeExecuted,
                'statementsCount' => $statementsCount,
            ];
        } finally {
            if ($tempExtractPath && file_exists($tempExtractPath)) {
                @unlink($tempExtractPath);
            }
        }
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

