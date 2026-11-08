<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\ImageUpload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImageUploadTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testRealImagesAreAcceptedByContent(): void
    {
        // PNG de 1×1 pixel, enviado com nome sem extensão
        $png = $this->upload(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='), 'foto');

        $this->assertSame('png', ImageUpload::extension($png));
    }

    public function testScriptsDisguisedAsImagesAreRejected(): void
    {
        $php = $this->upload("<?php echo 'oi';", 'logo.png', 'image/png');
        $html = $this->upload('<html><script>alert(1)</script></html>', 'logo.jpg', 'image/jpeg');
        $svg = $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg', 'image/svg+xml');

        $this->assertNull(ImageUpload::extension($php), 'O tipo vem do conteúdo, não do nome nem do navegador');
        $this->assertNull(ImageUpload::extension($html));
        $this->assertNull(ImageUpload::extension($svg), 'SVG só onde for permitido explicitamente');
        $this->assertSame('svg', ImageUpload::extension($svg, true));
    }

    private function upload(string $content, string $clientName, ?string $clientMime = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return new UploadedFile($path, $clientName, $clientMime, null, true);
    }
}
