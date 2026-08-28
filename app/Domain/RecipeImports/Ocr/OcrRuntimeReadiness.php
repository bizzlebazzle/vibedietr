<?php

namespace App\Domain\RecipeImports\Ocr;

use Imagick;
use Symfony\Component\Process\Process;
use Throwable;

final class OcrRuntimeReadiness
{
    /** @return list<string> */
    public function failures(): array
    {
        if (! (bool) config('production.ocr.enabled')) {
            return [];
        }
        $failures = [];
        try {
            $executable = (string) config('production.ocr.tesseract_executable');
            if ($executable === '' || ! is_executable($executable)) {
                $failures[] = 'tesseract executable unavailable';
            } else {
                $process = new Process([$executable, '--version']);
                $process->setTimeout(3);
                $process->run();
                if (! $process->isSuccessful() || ! str_starts_with($process->getOutput(), 'tesseract 5.')) {
                    $failures[] = 'tesseract major version mismatch';
                }
            }
            $trainedData = (string) config('production.ocr.traineddata_path');
            $expectedHash = strtolower((string) config('production.ocr.traineddata_sha256'));
            if (! is_readable($trainedData)) {
                $failures[] = 'english trained data unavailable';
            } elseif (! hash_equals($expectedHash, hash_file('sha256', $trainedData) ?: '')) {
                $failures[] = 'english trained data version mismatch';
            }
            $formats = Imagick::queryFormats('HEI*');
            if (! in_array('HEIC', $formats, true) || ! in_array('HEIF', $formats, true)) {
                $failures[] = 'heic/heif decoder unavailable';
            }
            foreach (['libheif1', 'libheif-plugin-libde265'] as $package) {
                $decoder = new Process(['/usr/bin/dpkg-query', '-W', '-f=${Version}', $package]);
                $decoder->setTimeout(3);
                $decoder->run();
                if (! $decoder->isSuccessful()
                    || trim($decoder->getOutput()) !== (string) config('production.ocr.heic_decoder_version')) {
                    $failures[] = 'heic/heif decoder version mismatch';
                    break;
                }
            }
        } catch (Throwable) {
            $failures[] = 'ocr runtime check failed';
        }

        return $failures;
    }
}
