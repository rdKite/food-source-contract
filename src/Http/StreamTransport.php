<?php

declare(strict_types=1);

namespace FoodSourceContract\Http;

use RuntimeException;

/**
 * A real service over HTTP, with PHP's own stream wrapper (no client library).
 */
final readonly class StreamTransport implements Transport
{
    public function __construct(
        private string $baseUrl,
        private float $timeoutSeconds = 10.0,
    ) {}

    public function get(string $path, array $query = []): Response
    {
        $url = rtrim($this->baseUrl, '/').$path.($query === [] ? '' : '?'.http_build_query($query, encoding_type: PHP_QUERY_RFC3986));

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'timeout' => $this->timeoutSeconds,
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new RuntimeException("No response from {$url}");
        }

        $raw = http_get_last_response_headers() ?? [];
        preg_match('#^HTTP/\S+\s+(\d{3})#', $raw[0] ?? '', $status);

        $headers = [];
        foreach (array_slice($raw, 1) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }

        return new Response((int) ($status[1] ?? 0), $headers, $body);
    }
}
