<?php

namespace App\Integrations\RecipeWebpages;

use InvalidArgumentException;

final class WebpageUrlValidator
{
    public function validate(string $input): WebpageUrl
    {
        if ($input === '' || strlen($input) > (int) config('production.imports.max_url_length', 2048)) {
            throw new InvalidArgumentException('Invalid URL length.');
        }

        $parts = parse_url($input);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('An absolute URL is required.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (! in_array($scheme, ['http', 'https'], true)
            || ! in_array($port, [80, 443], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || $host === ''
            || in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.localhost')) {
            throw new InvalidArgumentException('Unsupported URL destination.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('Invalid URL host.');
        }

        $fragmentless = preg_replace('/#.*$/s', '', $input);
        if (! is_string($fragmentless)) {
            throw new InvalidArgumentException('Invalid URL.');
        }

        return new WebpageUrl($fragmentless, $scheme, $host, $port);
    }
}
