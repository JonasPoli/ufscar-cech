<?php

namespace App\Controller\admin;

use App\Service\Backup\DatabaseBackupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminDatabaseBackupController extends AbstractController
{
    public function __construct(
        private readonly DatabaseBackupService $backupService
    ) {}

    #[Route('/admin/database', name: 'app_admin_database_root', methods: ['GET'])]
    #[Route('/admin/database/backup', name: 'app_admin_database_backup_index', methods: ['GET'])]
    public function index(): Response
    {
        $overview = $this->backupService->getDatabaseOverview();
        $backups = $this->backupService->listBackups();

        return $this->render('admin/database/backup.html.twig', [
            'overview' => $overview,
            'backups' => $backups,
        ]);
    }

    #[Route('/admin/database/backup/stream', name: 'app_admin_database_backup_stream', methods: ['GET'])]
    public function streamBackup(Request $request): StreamedResponse
    {
        $useZip = $request->query->getBoolean('zip', true);

        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no'); // Disable proxy buffering (Nginx)

        $response->setCallback(function() use ($useZip) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            $sendEvent = function(array $data) {
                echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $progressCallback = function(
                string $status,
                string $currentTable,
                int $tableIndex,
                int $totalTables,
                int $percent,
                int $processedRows,
                int $totalRows
            ) use ($sendEvent) {
                $sendEvent([
                    'status' => $status,
                    'table' => $currentTable,
                    'currentTable' => $tableIndex,
                    'totalTables' => $totalTables,
                    'percent' => $percent,
                    'processedRows' => $processedRows,
                    'totalRows' => $totalRows,
                ]);
            };

            try {
                $result = $this->backupService->exportDatabase($useZip, $progressCallback);

                $sendEvent([
                    'status' => 'completed',
                    'percent' => 100,
                    'filename' => $result['filename'],
                    'fileSizeFormatted' => $result['fileSizeFormatted'],
                    'sqlSizeFormatted' => $result['sqlSizeFormatted'],
                    'tableCount' => $result['tableCount'],
                    'rowCount' => $result['rowCount'],
                    'durationSec' => $result['durationSec'],
                    'downloadUrl' => $this->generateUrl('app_admin_database_backup_download', ['filename' => $result['filename']]),
                ]);
            } catch (\Throwable $e) {
                $sendEvent([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ]);
            }
        });

        return $response;
    }

    #[Route('/admin/database/backup/generate', name: 'app_admin_database_backup_generate', methods: ['POST'])]
    public function generate(Request $request): Response
    {
        $useZip = $request->request->getBoolean('zip', true);

        try {
            $result = $this->backupService->exportDatabase($useZip);
            $this->addFlash('success', sprintf(
                'Superdump gerado com sucesso! Arquivo "%s" (%s) gerado em %s segundos.',
                $result['filename'],
                $result['fileSizeFormatted'],
                $result['durationSec']
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erro ao gerar superdump: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_database_backup_index');
    }

    #[Route('/admin/database/backup/download', name: 'app_admin_database_backup_download_latest', methods: ['GET'])]
    public function downloadLatest(): Response
    {
        $backups = $this->backupService->listBackups();
        if (empty($backups)) {
            $this->addFlash('error', 'Nenhum arquivo de backup encontrado para download.');
            return $this->redirectToRoute('app_admin_database_backup_index');
        }

        return $this->download($backups[0]['filename']);
    }

    #[Route('/admin/database/backup/download/{filename}', name: 'app_admin_database_backup_download', methods: ['GET'])]
    #[Route('/admin/database/backup/{filename}', name: 'app_admin_database_backup_file', requirements: ['filename' => '.+\.(zip|sql)'], methods: ['GET'])]
    #[Route('/admin/database/{filename}', name: 'app_admin_database_catchall', methods: ['GET'])]
    public function download(string $filename): Response
    {
        if ($filename === 'undefined' || $filename === 'backup' || trim($filename) === '') {
            return $this->downloadLatest();
        }

        $file = $this->backupService->getBackupFile($filename);

        if (!$file) {
            $backups = $this->backupService->listBackups();
            if (!empty($backups)) {
                $this->addFlash('warning', "Arquivo '{$filename}' não encontrado. Baixando o backup mais recente.");
                return $this->download($backups[0]['filename']);
            }

            $this->addFlash('error', 'Arquivo de backup não encontrado ou inválido.');
            return $this->redirectToRoute('app_admin_database_backup_index');
        }

        $response = new BinaryFileResponse($file->getRealPath());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file->getFilename()
        );
        $response->headers->set('Content-Type', str_ends_with($filename, '.zip') ? 'application/zip' : 'application/sql');

        return $response;
    }

    #[Route('/admin/database/backup/delete/{filename}', name: 'app_admin_database_backup_delete', methods: ['POST'])]
    public function delete(string $filename, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete_backup_' . $filename, (string)$request->request->get('_token'))) {
            if ($this->backupService->deleteBackup($filename)) {
                $this->addFlash('success', "Arquivo de backup '{$filename}' excluído com sucesso.");
            } else {
                $this->addFlash('error', "Não foi possível excluir o arquivo de backup '{$filename}'.");
            }
        } else {
            $this->addFlash('error', 'Token de segurança inválido.');
        }

        return $this->redirectToRoute('app_admin_database_backup_index');
    }
}
