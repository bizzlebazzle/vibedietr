<?php

namespace App\Integrations\RecipeWebpages;

final class CurlWebpageTransport implements WebpageTransport
{
    public function request(ValidatedDestination $destination): WebpageResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new WebpageFetchException('fetch_unavailable', 'network', true);
        }

        $buffer = new DecodedHtmlBuffer((int) config('production.imports.max_response_bytes', 2_097_152));
        $address = str_contains($destination->address, ':') ? '['.$destination->address.']' : $destination->address;

        curl_setopt_array($handle, [
            CURLOPT_URL => $destination->url->value,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => (int) config('production.imports.connect_timeout_seconds', 3),
            CURLOPT_TIMEOUT => (int) config('production.imports.timeout_seconds', 15),
            CURLOPT_USERAGENT => (string) config('production.imports.user_agent'),
            CURLOPT_HTTPHEADER => ['Accept: text/html, application/xhtml+xml;q=0.9', 'Accept-Language: en;q=0.5'],
            CURLOPT_ENCODING => '',
            CURLOPT_RESOLVE => [$destination->url->host.':'.$destination->url->port.':'.$address],
            CURLOPT_HEADERFUNCTION => fn ($curl, string $line): int => $buffer->header($line),
            CURLOPT_WRITEFUNCTION => fn ($curl, string $chunk): int => $buffer->append($chunk),
        ]);

        $completed = curl_exec($handle);
        $error = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($buffer->oversized) {
            throw new WebpageFetchException('response_too_large', 'oversized');
        }
        if ($completed === false) {
            $timeout = in_array($error, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST], true);
            throw new WebpageFetchException($timeout ? 'fetch_timeout' : 'fetch_failed', $timeout ? 'timeout' : 'network', $timeout);
        }

        return new WebpageResponse($status, $buffer->headers, $buffer->body);
    }
}
