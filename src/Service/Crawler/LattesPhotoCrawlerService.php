<?php

namespace App\Service\Crawler;

use App\Entity\Researcher;
use App\Repository\ResearcherRepository;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Serviço de coleta e download automatizado das fotos de perfil oficiais dos pesquisadores no CNPq Lattes.
 *
 * Executa requisições aos servlets públicos do CNPq (wspessoa e buscatextual),
 * resolvendo o identificador interno 'K-ID' a partir do ID Lattes de 16 dígitos,
 * validando o MIME-type binário e armazenando as fotos em `public/uploads/photos/`.
 */
class LattesPhotoCrawlerService
{
    /** Diretório físico de destino das imagens no servidor */
    private string $photosDir;

    /**
     * @param EntityManagerInterface $em Gerenciador de entidades do Doctrine
     * @param ResearcherRepository $researcherRepo Repositório de pesquisadores
     * @param StringNormalizer $normalizer Utilitário de normalização de strings
     * @param LoggerInterface|null $logger Logger do sistema
     * @param string $photosDir Caminho injetado via autowiring para a pasta de fotos
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResearcherRepository $researcherRepo,
        private readonly StringNormalizer $normalizer,
        private readonly ?LoggerInterface $logger = null,
        #[Autowire('%kernel.project_dir%/public/uploads/photos')] string $photosDir = ''
    ) {
        $this->photosDir = $photosDir;
        if (!is_dir($this->photosDir)) {
            @mkdir($this->photosDir, 0777, true);
        }
    }

    /**
     * Tenta baixar e salvar a foto de perfil do pesquisador a partir dos servlets do CNPq.
     *
     * @param Researcher $researcher Entidade do pesquisador
     * @return string|null Caminho relativo da foto salva (ex: 'uploads/photos/123456.jpg') ou null caso não encontrada
     */
    public function crawlPhoto(Researcher $researcher): ?string
    {
        $idLattes = $researcher->getIdLattes();
        if (empty($idLattes)) {
            return null;
        }

        // 1. Resolve K-ID from lattes.cnpq.br initial redirect
        $kId = $this->resolveKId($idLattes);

        // Target endpoints used by CNPq Lattes photo servlets
        $endpoints = [];
        if ($kId !== null) {
            $endpoints[] = 'https://servicosweb.cnpq.br/wspessoa/servletrecuperafoto?tipo=1&id=' . $kId;
            $endpoints[] = 'http://buscatextual.cnpq.br/buscatextual/servletrecuperafoto?id=' . $kId;
        }
        $endpoints[] = 'https://servicosweb.cnpq.br/wspessoa/servletrecuperafoto?tipo=1&id=' . $idLattes;
        $endpoints[] = 'http://buscatextual.cnpq.br/buscatextual/servletrecuperafoto?id=' . $idLattes;

        foreach ($endpoints as $url) {
            $photoBinary = $this->fetchBinary($url);
            if ($photoBinary !== null && strlen($photoBinary) > 600) {
                // Check if it's a valid JPEG/PNG/GIF image binary (not an HTML error page)
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->buffer($photoBinary);

                if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                    $ext = ($mime === 'image/png') ? 'png' : 'jpg';
                    $filename = $idLattes . '.' . $ext;
                    $targetPath = $this->photosDir . '/' . $filename;

                    file_put_contents($targetPath, $photoBinary);

                    $publicUrl = '/uploads/photos/' . $filename;
                    $researcher->setPhotoUrl($publicUrl);
                    $this->em->flush();

                    $this->logger?->info(sprintf('Photo successfully fetched for %s (%s)', $researcher->getFullName(), $idLattes));
                    return $publicUrl;
                }
            }
        }

        return null;
    }

    /**
     * Resolves the internal 10-character CNPq K-ID from the 16-digit Lattes ID.
     */
    public function resolveKId(string $idLattes): ?string
    {
        $url = 'http://lattes.cnpq.br/' . $idLattes;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
        
        $html = curl_exec($ch);
        curl_close($ch);

        if ($html && preg_match('/name=["\']id["\']\s+value=["\']([A-Za-z0-9]{10})["\']/', (string)$html, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Imports photos in batch from a directory.
     * Matches files by:
     * 1. Exact 16-digit ID Lattes: 1012351287140134.jpg
     * 2. Slug: roniberto-morato-do-amaral.jpg
     * 3. Normalized full name: roniberto_morato_do_amaral.jpg
     */
    public function importFromDirectory(string $sourceDir): array
    {
        if (!is_dir($sourceDir)) {
            throw new \InvalidArgumentException("Diretório não encontrado: $sourceDir");
        }

        $allResearchers = $this->researcherRepo->findAll();
        $byLattes = [];
        $bySlug = [];
        $byNormalizedName = [];

        foreach ($allResearchers as $r) {
            $byLattes[$r->getIdLattes()] = $r;
            $bySlug[$r->getSlug()] = $r;
            $byNormalizedName[StringNormalizer::normalizeString($r->getFullName())] = $r;
        }

        $imported = 0;
        $failed = 0;
        $matched = [];

        $files = scandir($sourceDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $sourcePath = $sourceDir . '/' . $file;
            if (!is_file($sourcePath)) {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }

            $filenameNoExt = pathinfo($file, PATHINFO_FILENAME);
            $cleanName = trim($filenameNoExt);

            // Match candidate
            $targetResearcher = null;
            if (isset($byLattes[$cleanName])) {
                $targetResearcher = $byLattes[$cleanName];
            } elseif (isset($bySlug[$cleanName])) {
                $targetResearcher = $bySlug[$cleanName];
            } else {
                $normalized = StringNormalizer::normalizeString(str_replace(['_', '-'], ' ', $cleanName));
                if (isset($byNormalizedName[$normalized])) {
                    $targetResearcher = $byNormalizedName[$normalized];
                }
            }

            if ($targetResearcher) {
                $destFilename = $targetResearcher->getIdLattes() . '.' . $ext;
                $destPath = $this->photosDir . '/' . $destFilename;
                copy($sourcePath, $destPath);

                $publicUrl = '/uploads/photos/' . $destFilename;
                $targetResearcher->setPhotoUrl($publicUrl);
                $matched[] = [
                    'researcher' => $targetResearcher->getFullName(),
                    'idLattes' => $targetResearcher->getIdLattes(),
                    'file' => $file,
                ];
                $imported++;
            } else {
                $failed++;
            }
        }

        $this->em->flush();

        return [
            'imported' => $imported,
            'unmatched' => $failed,
            'matched' => $matched,
        ];
    }

    /**
     * Imports photos from a ZIP archive.
     */
    public function importFromZip(string $zipPath): array
    {
        if (!file_exists($zipPath)) {
            throw new \InvalidArgumentException("Arquivo ZIP não encontrado: $zipPath");
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException("Não foi possível abrir o arquivo ZIP: $zipPath");
        }

        $tempExtractDir = sys_get_temp_dir() . '/cech_photos_' . uniqid();
        @mkdir($tempExtractDir, 0777, true);

        $zip->extractTo($tempExtractDir);
        $zip->close();

        $result = $this->importFromDirectory($tempExtractDir);

        // Clean up temp dir
        $this->deleteDirectory($tempExtractDir);

        return $result;
    }

    /**
     * Uploads and assigns a photo manually to a researcher.
     */
    public function assignUploadedPhoto(Researcher $researcher, UploadedFile $file): string
    {
        $ext = $file->guessExtension() ?: 'jpg';
        $filename = $researcher->getIdLattes() . '.' . $ext;
        $file->move($this->photosDir, $filename);

        $publicUrl = '/uploads/photos/' . $filename;
        $researcher->setPhotoUrl($publicUrl);
        $this->em->flush();

        return $publicUrl;
    }

    private function fetchBinary(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'Referer: http://lattes.cnpq.br/',
        ]);

        $output = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && is_string($output)) {
            return $output;
        }

        return null;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $path = $dir . '/' . $file;
                if (is_dir($path)) {
                    $this->deleteDirectory($path);
                } else {
                    @unlink($path);
                }
            }
        }
        @rmdir($dir);
    }
}
