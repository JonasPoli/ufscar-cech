<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:photos:generate-webp',
    description: 'Gera versões otimizadas WebP (.webp e -256.webp) para todas as fotos de docentes em public/uploads/photos/'
)]
class GenerateWebpPhotosCommand extends Command
{
    private string $photosDir;

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads/photos')] string $photosDir = ''
    ) {
        $this->photosDir = $photosDir;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Sobrescrever versões WebP existentes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        if (!is_dir($this->photosDir)) {
            $io->error(sprintf('Diretório %s não existe.', $this->photosDir));
            return Command::FAILURE;
        }

        if (!function_exists('imagewebp')) {
            $io->error('A extensão GD com suporte a WebP não está disponível no PHP.');
            return Command::FAILURE;
        }

        $files = glob($this->photosDir . '/*.{jpg,jpeg,png,JPG,JPEG,PNG}', GLOB_BRACE);
        if (empty($files)) {
            $io->warning('Nenhuma foto encontrada para processamento.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Otimizando %d fotos para formato WebP...', count($files)));
        $progressBar = $io->createProgressBar(count($files));

        $countFull = 0;
        $countThumb = 0;

        foreach ($files as $file) {
            $baseName = pathinfo($file, PATHINFO_FILENAME);
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            $webpFull = $this->photosDir . '/' . $baseName . '.webp';
            $webp256 = $this->photosDir . '/' . $baseName . '-256.webp';

            if (!$force && file_exists($webpFull) && file_exists($webp256)) {
                $progressBar->advance();
                continue;
            }

            $src = match ($ext) {
                'jpg', 'jpeg' => @imagecreatefromjpeg($file),
                'png' => @imagecreatefrompng($file),
                default => null,
            };

            if (!$src) {
                $progressBar->advance();
                continue;
            }

            $width = imagesx($src);
            $height = imagesy($src);

            // 1. Full-size WebP
            if ($force || !file_exists($webpFull)) {
                imagewebp($src, $webpFull, 80);
                $countFull++;
            }

            // 2. 256x256 WebP thumbnail (ideal para cards de 128x128 retina)
            if ($force || !file_exists($webp256)) {
                $maxDim = 256;
                if ($width > $maxDim || $height > $maxDim) {
                    $ratio = min($maxDim / $width, $maxDim / $height);
                    $newW = (int) round($width * $ratio);
                    $newH = (int) round($height * $ratio);
                } else {
                    $newW = $width;
                    $newH = $height;
                }

                $thumb = imagecreatetruecolor($newW, $newH);
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
                imagewebp($thumb, $webp256, 82);
                imagedestroy($thumb);
                $countThumb++;
            }

            imagedestroy($src);
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);
        $io->success(sprintf('Concluído! %d fotos full WebP e %d thumbnails -256.webp geradas.', $countFull, $countThumb));

        return Command::SUCCESS;
    }
}
