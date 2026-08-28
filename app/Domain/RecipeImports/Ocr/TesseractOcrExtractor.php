<?php

namespace App\Domain\RecipeImports\Ocr;

use App\Queue\Exceptions\NonRetryableJobException;
use App\Queue\Exceptions\RetryableJobException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class TesseractOcrExtractor implements OcrExtractor
{
    public const PROVIDER = 'tesseract';

    public function extract(string $canonicalBytes, string $correlationId): OcrResult
    {
        $temporary = tempnam(sys_get_temp_dir(), 'rec17-');
        if ($temporary === false) {
            throw new RetryableJobException('ocr_temporary_input_failed');
        }

        try {
            if (file_put_contents($temporary, $canonicalBytes, LOCK_EX) !== strlen($canonicalBytes)) {
                throw new RetryableJobException('ocr_temporary_input_failed');
            }
            @chmod($temporary, 0600);
            $process = new Process([
                (string) config('production.ocr.tesseract_executable', '/usr/bin/tesseract'),
                $temporary,
                'stdout',
                '-l', (string) (config('production.ocr.language') ?: 'eng'),
                '--oem', '1',
                '--psm', '6',
                'tsv',
            ]);
            $process->setEnv(['OMP_THREAD_LIMIT' => '1']);
            $process->setTimeout(min(50, (int) config('production.ocr.timeout_seconds', 60)));
            $process->setIdleTimeout(20);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RetryableJobException('tesseract_execution_failed');
            }
            $output = $process->getOutput();
            if (strlen($output) > (int) config('production.ocr.max_output_bytes', 8_388_608)) {
                throw new NonRetryableJobException('ocr_output_too_large');
            }

            return $this->parse($output);
        } catch (ProcessTimedOutException) {
            throw new RetryableJobException('tesseract_timeout');
        } catch (NonRetryableJobException|RetryableJobException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RetryableJobException('tesseract_execution_failed');
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function parse(string $tsv): OcrResult
    {
        $rows = preg_split('/\R/', trim($tsv)) ?: [];
        array_shift($rows);
        $groups = [];
        foreach ($rows as $row) {
            $columns = explode("\t", $row, 12);
            if (count($columns) !== 12 || trim($columns[11]) === '' || ! is_numeric($columns[10])) {
                continue;
            }
            $confidence = (float) $columns[10];
            if ($confidence < 0) {
                continue;
            }
            $key = implode(':', array_slice($columns, 1, 4));
            $groups[$key][] = ['text' => trim($columns[11]), 'confidence' => $confidence];
        }

        $lines = [];
        $warnings = [];
        foreach ($groups as $words) {
            $text = implode(' ', array_column($words, 'text'));
            $unreliable = count(array_filter($words, fn (array $word): bool => $word['confidence'] < 70));
            $uncertain = count(array_filter($words, fn (array $word): bool => $word['confidence'] >= 70 && $word['confidence'] < 90));
            $critical = preg_match('/(?:\d|[¼½¾⅓⅔⅛⅜⅝⅞]|\b(?:cup|tsp|tbsp|g|kg|ml|l|oz|minute|hour|°[cf]|serves?)\b)/iu', $text) === 1;
            $criticalUncertain = $critical && ($unreliable + $uncertain > 0);
            $ratio = $unreliable / count($words);
            $category = match (true) {
                $ratio > 0.4 => 'unreliable',
                $unreliable > 0 || $criticalUncertain || $uncertain / count($words) > 0.1 => 'uncertain',
                default => 'reliable',
            };
            if ($category !== 'reliable') {
                $warnings[] = 'low_confidence_text';
            }
            if ($criticalUncertain) {
                $warnings[] = 'possible_extraction_error';
            }
            $lines[] = new OcrTextLine($text, $category, $criticalUncertain, min(array_column($words, 'confidence')));
        }

        return new OcrResult(
            text: implode("\n", array_map(fn (OcrTextLine $line): string => $line->text, $lines)),
            lines: $lines,
            warnings: array_values(array_unique($warnings)),
            provider: self::PROVIDER,
            providerVersion: (string) (config('production.ocr.tesseract_version') ?: '5'),
            language: (string) (config('production.ocr.language') ?: 'eng'),
        );
    }
}
