<?php

declare(strict_types=1);

namespace FoodSourceContract\Http;

use Closure;

/**
 * In-process: a function from request to response, e.g. the reference mock.
 */
final readonly class CallableTransport implements Transport
{
    /**
     * @param  Closure(string, array<string, string>): Response  $handler
     */
    public function __construct(private Closure $handler) {}

    public function get(string $path, array $query = []): Response
    {
        return ($this->handler)($path, $query);
    }
}
