<?php

namespace App\Domain\RecipeImports\Images;

use App\Queue\Exceptions\NonRetryableJobException;
use Imagick;
use ImagickException;

final class ImageCanonicalizer
{
    private const MIMES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'heic' => 'image/heic', 'heif' => 'image/heif',
    ];

    public function canonicalize(string $bytes, string $extension, string $detectedMime): CanonicalImage
    {
        if (strlen($bytes) > 20_971_520 || ! isset(self::MIMES[$extension])) {
            throw new NonRetryableJobException('image_too_large');
        }
        if (! $this->signatureMatches($bytes, $extension)
            || ! $this->mimeMatches($extension, $detectedMime)) {
            throw new NonRetryableJobException('image_type_mismatch');
        }
        if ($extension === 'png' && str_contains($bytes, 'acTL')) {
            throw new NonRetryableJobException('multiple_image_frames');
        }

        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 256 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK, 512 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 1);
        // ImageMagick counts this global limit over the PHP worker lifetime.
        // The queue job's 60-second timeout is the per-import wall boundary.
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 3600);

        try {
            $image = new Imagick;
            $image->pingImageBlob($bytes);
            if ($image->getNumberImages() !== 1) {
                throw new NonRetryableJobException('multiple_image_frames');
            }
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $this->assertDimensions($width, $height);
            $image->clear();

            $image->readImageBlob($bytes);
            if (count($image) !== 1) {
                throw new NonRetryableJobException('multiple_image_frames');
            }
            $image->setIteratorIndex(0);
            $this->assertDimensions($image->getImageWidth(), $image->getImageHeight());
            $format = strtoupper($image->getImageFormat());
            if (! $this->decoderMatches($extension, $format)) {
                throw new NonRetryableJobException('image_type_mismatch');
            }

            $this->normalizeOrientation($image);
            $image->setImagePage(0, 0, 0, 0);
            $image->setImageBackgroundColor('white');
            if ($image->getImageAlphaChannel()) {
                $flattened = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $image->clear();
                $image = $flattened;
            }
            $image->stripImage();
            $image->setImageFormat('png');
            $image->setOption('png:exclude-chunk', 'all');
            $image->setImageCompressionQuality(90);
            $canonical = $image->getImageBlob();
            $result = new CanonicalImage(
                $canonical,
                'image/png',
                $image->getImageWidth(),
                $image->getImageHeight(),
                (string) (config('production.ocr.preprocessing_version') ?: 'rec17-v1'),
            );
            $image->clear();

            return $result;
        } catch (NonRetryableJobException $exception) {
            throw $exception;
        } catch (ImagickException) {
            throw new NonRetryableJobException('corrupt_or_unsupported_image');
        }
    }

    private function normalizeOrientation(Imagick $image): void
    {
        switch ($image->getImageOrientation()) {
            case 2:
                $image->flopImage();
                break;
            case 3:
                $image->rotateImage('none', 180);
                break;
            case 4:
                $image->flipImage();
                break;
            case 5:
                $image->flopImage();
                $image->rotateImage('none', 90);
                break;
            case 6:
                $image->rotateImage('none', 90);
                break;
            case 7:
                $image->flopImage();
                $image->rotateImage('none', -90);
                break;
            case 8:
                $image->rotateImage('none', -90);
                break;
        }

        $image->setImageOrientation(1);
    }

    private function assertDimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1 || $width > 50_000_000 || $height > 50_000_000
            || $width * $height > 50_000_000) {
            throw new NonRetryableJobException('image_pixel_limit');
        }
    }

    private function signatureMatches(string $bytes, string $extension): bool
    {
        return match ($extension) {
            'jpg', 'jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'png' => str_starts_with($bytes, "\x89PNG\r\n\x1A\n"),
            'heic', 'heif' => strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp'
                && preg_match('/^(?:heic|heix|hevc|hevx|heim|heis|mif1|msf1)$/', substr($bytes, 8, 4)) === 1,
            default => false,
        };
    }

    private function mimeMatches(string $extension, string $mime): bool
    {
        return self::MIMES[$extension] === $mime
            || (in_array($extension, ['heic', 'heif'], true) && in_array($mime, ['image/heic', 'image/heif'], true));
    }

    private function decoderMatches(string $extension, string $format): bool
    {
        return match ($extension) {
            'jpg', 'jpeg' => in_array($format, ['JPEG', 'JPG'], true),
            'png' => str_starts_with($format, 'PNG'),
            'heic', 'heif' => in_array($format, ['HEIC', 'HEIF'], true),
            default => false,
        };
    }
}
