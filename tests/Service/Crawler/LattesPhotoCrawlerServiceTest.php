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
        // O teste de destino somente leitura precisa devolver a permissão para a pasta poder ser apagada
        @chmod($this->tempPhotosDir, 0777);
        $this->cleanDir($this->tempPhotosDir);
        $this->cleanDir($this->tempSourceDir);
    }

    public function testImportFromDirectory(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(ResearcherRepository::class);
        $normalizer = new StringNormalizer();

        $researcher = $this->researcher('1012351287140134', 'Roniberto Morato do Amaral', 'roniberto-morato-do-amaral');

        $repo->method('findAll')->willReturn([$researcher]);
        $em->expects($this->once())->method('flush');

        // Create sample photo in source dir
        file_put_contents($this->tempSourceDir . '/1012351287140134.jpg', $this->image('jpeg'));

        $service = new LattesPhotoCrawlerService($em, $repo, $normalizer, null, $this->tempPhotosDir);
        $result = $service->importFromDirectory($this->tempSourceDir);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['unmatched']);
        $this->assertSame([], $result['failed']);
        $this->assertSame('/uploads/photos/1012351287140134.jpg', $researcher->getPhotoUrl());
        $this->assertFileExists($this->tempPhotosDir . '/1012351287140134.jpg');
        $this->assertFileExists($this->tempPhotosDir . '/1012351287140134-256.webp', 'A miniatura que o <picture> do portal procura.');
    }

    public function testWebpSourceIsConvertedAndGetsTheThumbnailLikeEveryOtherPhoto(): void
    {
        $researcher = $this->researcher('1012351287140135', 'Docente da Foto Webp', 'docente-da-foto-webp');
        file_put_contents($this->tempSourceDir . '/docente-da-foto-webp.webp', $this->image('webp'));

        $result = $this->service([$researcher])->importFromDirectory($this->tempSourceDir);

        $this->assertSame(1, $result['imported']);
        $this->assertSame([], $result['failed']);
        // Como em storeBinaryPhoto: WebP vira JPEG, para o portal achar sempre o `-256.webp` pelo nome da foto
        $this->assertSame('/uploads/photos/1012351287140135.jpg', $researcher->getPhotoUrl());
        $this->assertFileExists($this->tempPhotosDir . '/1012351287140135.jpg');
        $this->assertFileExists($this->tempPhotosDir . '/1012351287140135-256.webp');
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->file($this->tempPhotosDir . '/1012351287140135.jpg'));
    }

    public function testPngSourceKeepsItsFormatAndGetsTheThumbnail(): void
    {
        $researcher = $this->researcher('1012351287140136', 'Docente da Foto Png', 'docente-da-foto-png');
        file_put_contents($this->tempSourceDir . '/1012351287140136.png', $this->image('png'));

        $result = $this->service([$researcher])->importFromDirectory($this->tempSourceDir);

        $this->assertSame(1, $result['imported']);
        $this->assertSame('/uploads/photos/1012351287140136.png', $researcher->getPhotoUrl());
        $this->assertFileExists($this->tempPhotosDir . '/1012351287140136-256.webp');
    }

    public function testFileThatIsNotAnImageIsNotCountedAsImportedAndKeepsThePreviousPhoto(): void
    {
        $researcher = $this->researcher('1012351287140137', 'Docente da Foto Quebrada', 'docente-da-foto-quebrada');
        $researcher->setPhotoUrl('/uploads/photos/anterior.jpg');
        file_put_contents($this->tempSourceDir . '/1012351287140137.jpg', str_repeat('isto não é uma imagem. ', 80));
        file_put_contents($this->tempSourceDir . '/sem-docente.jpg', $this->image('jpeg'));

        $result = $this->service([$researcher])->importFromDirectory($this->tempSourceDir);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['unmatched']);
        $this->assertSame(['1012351287140137.jpg'], $result['failed']);
        $this->assertSame('/uploads/photos/anterior.jpg', $researcher->getPhotoUrl(), 'A foto atual não é trocada por um arquivo inválido.');
        $this->assertFileDoesNotExist($this->tempPhotosDir . '/1012351287140137.jpg');
    }

    public function testFileThatCannotBeWrittenIsNotCountedAsImported(): void
    {
        $researcher = $this->researcher('1012351287140138', 'Docente da Pasta Fechada', 'docente-da-pasta-fechada');
        file_put_contents($this->tempSourceDir . '/1012351287140138.jpg', $this->image('jpeg'));

        // Pasta de destino sem permissão de escrita: antes, o copy() falhava em silêncio e o docente ficava apontando para nada
        chmod($this->tempPhotosDir, 0555);
        if (is_writable($this->tempPhotosDir)) {
            $this->markTestSkipped('O usuário atual escreve em pasta somente leitura (root): não dá para simular a falha.');
        }

        $result = $this->service([$researcher])->importFromDirectory($this->tempSourceDir);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(['1012351287140138.jpg'], $result['failed']);
        $this->assertNull($researcher->getPhotoUrl());
    }

    /**
     * @param Researcher[] $researchers
     */
    private function service(array $researchers): LattesPhotoCrawlerService
    {
        $repo = $this->createMock(ResearcherRepository::class);
        $repo->method('findAll')->willReturn($researchers);

        return new LattesPhotoCrawlerService($this->createMock(EntityManagerInterface::class), $repo, new StringNormalizer(), null, $this->tempPhotosDir);
    }

    private function researcher(string $idLattes, string $name, string $slug): Researcher
    {
        $researcher = new Researcher();
        $researcher->setIdLattes($idLattes);
        $researcher->setFullName($name);
        $researcher->setSlug($slug);

        return $researcher;
    }

    /**
     * Imagem 300x300 com degradê (acima do tamanho mínimo que o serviço aceita para uma foto).
     */
    private function image(string $format): string
    {
        $image = imagecreatetruecolor(300, 300);
        for ($i = 0; $i < 300; $i++) {
            imageline($image, 0, $i, 299, $i, imagecolorallocate($image, $i % 256, (300 - $i) % 256, ($i * 7) % 256));
        }

        ob_start();
        match ($format) {
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 80),
            default => imagejpeg($image, null, 85),
        };
        $binary = (string)ob_get_clean();
        imagedestroy($image);

        return $binary;
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
