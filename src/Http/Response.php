<?php

declare(strict_types=1);

namespace FoodSourceContract\Http;

use JsonException;

/**
 * An HTTP response as the conformance suite and the mock see it.
 */
final readonly class Response
{
    /** @var array<string, string> Header names in lower case. */
    public array $headers;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        array $headers,
        public string $body,
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** The decoded body, or null if it is not JSON. */
    public function json(): mixed
    {
        try {
            return json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @param  array<mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function ofJson(int $status, array $data, array $headers = []): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', ...$headers],
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        );
    }
}
