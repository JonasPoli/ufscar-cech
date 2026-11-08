<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Imagem enviada pelo admin que vai para dentro de `public/`. O tipo é lido do conteúdo do arquivo (não do nome nem do
 * tipo informado pelo navegador) e só imagens comuns são aceitas: um `.php`, `.html` ou `.svg` disfarçado, servido no
 * domínio do portal, executaria código ou script.
 */
final class ImageUpload
{
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /** Extensão segura para gravar o arquivo, ou null se ele não for uma imagem aceita. */
    public static function extension(UploadedFile $file, bool $allowSvg = false): ?string
    {
        if (!$file->isValid()) {
            return null;
        }
        $mime = (string)$file->getMimeType();
        if (isset(self::TYPES[$mime])) {
            return self::TYPES[$mime];
        }
        if ($allowSvg && $mime === 'image/svg+xml') {
            return 'svg';
        }

        return null;
    }

    public static function acceptedLabel(bool $allowSvg = false): string
    {
        return $allowSvg ? 'PNG, JPG, WebP, GIF ou SVG' : 'PNG, JPG, WebP ou GIF';
    }
}
