<?php

declare(strict_types=1);

namespace FoodSourceContract\Http;

/**
 * How the conformance suite reaches a food source: over HTTP, or in-process.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $query
     */
    public function get(string $path, array $query = []): Response;
}
