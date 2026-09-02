<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Optimiza imágenes sensibles y las guarda exclusivamente en disco privado. */
class PrivateImageOptimizer
{
    private const MAX_EDGE = 1600;

    private const WEBP_QUALITY = 78;

    /**
     * @return array{path: string, mime: string, original_size: int, stored_size: int}
     */
    public function store(UploadedFile $file, string $directory, string $prefix): array
    {
        $originalSize = (int) $file->getSize();
        $source = @file_get_contents($file->getRealPath());
        $image = $source !== false ? @imagecreatefromstring($source) : false;

        if ($image === false) {
            throw new RuntimeException('No se pudo procesar la imagen del comprobante.');
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, self::MAX_EDGE / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $optimized = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($optimized === false) {
                throw new RuntimeException('No se pudo preparar la imagen optimizada.');
            }

            imagealphablending($optimized, false);
            imagesavealpha($optimized, true);
            $transparent = imagecolorallocatealpha($optimized, 255, 255, 255, 127);
            imagefilledrectangle($optimized, 0, 0, $targetWidth, $targetHeight, $transparent);
            imagecopyresampled($optimized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            ob_start();
            $encoded = imagewebp($optimized, null, self::WEBP_QUALITY);
            $contents = ob_get_clean();
            imagedestroy($optimized);

            if (! $encoded || ! is_string($contents) || $contents === '') {
                throw new RuntimeException('No se pudo comprimir el comprobante.');
            }

            $path = trim($directory, '/').'/'.Str::slug($prefix).'-'.Str::uuid().'.webp';
            if (! Storage::disk('local')->put($path, $contents)) {
                throw new RuntimeException('No se pudo guardar el comprobante.');
            }

            return [
                'path' => $path,
                'mime' => 'image/webp',
                'original_size' => $originalSize,
                'stored_size' => strlen($contents),
            ];
        } finally {
            imagedestroy($image);
        }
    }
}
