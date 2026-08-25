<?php

namespace App\Tests\Service\Crawler;

use App\Entity\Researcher;
use App\Repository\ResearcherRepository;
use App\Service\Crawler\LattesPhotoCrawlerService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LattesPhotoCrawlerServiceTest extends TestCase
{
    private string $tempPhotosDir;
    private string $tempSourceDir;

    protected function setUp(): void
    {
        $this->tempPhotosDir = sys_get_temp_dir() . '/test_photos_dest_' . uniqid();
        $this->tempSourceDir = sys_get_temp_dir() . '/test_photos_src_' . uniqid();
        @mkdir($this->tempPhotosDir, 0777, true);
        @mkdir($this->tempSourceDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tempPhotosDir);
        $this->cleanDir($this->tempSourceDir);
    }

    public function testImportFromDirectory(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(ResearcherRepository::class);
        $normalizer = new StringNormalizer();

        $researcher = new Researcher();
        $researcher->setIdLattes('1012351287140134');
        $researcher->setFullName('Roniberto Morato do Amaral');
        $researcher->setSlug('roniberto-morato-do-amaral');

        $repo->method('findAll')->willReturn([$researcher]);
        $em->expects($this->once())->method('flush');

        // Create sample photo in source dir
        file_put_contents($this->tempSourceDir . '/1012351287140134.jpg', 'dummy image content');

        $service = new LattesPhotoCrawlerService($em, $repo, $normalizer, null, $this->tempPhotosDir);
        $result = $service->importFromDirectory($this->tempSourceDir);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['unmatched']);
        $this->assertSame('/uploads/photos/1012351287140134.jpg', $researcher->getPhotoUrl());
        $this->assertFileExists($this->tempPhotosDir . '/1012351287140134.jpg');
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($dir . '/' . $file);
            }
        }
        @rmdir($dir);
    }
}
