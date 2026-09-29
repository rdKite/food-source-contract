<?php

declare(strict_types=1);

/*
 * The reference mock over HTTP, for services and apps that want a real URL:
 *
 *   php -S 127.0.0.1:8200 mock/router.php
 *   FOOD_SOURCE_MOCK_MODE=unavailable php -S 127.0.0.1:8200 mock/router.php
 */

use FoodSourceContract\Http\Response;
use FoodSourceContract\Mock\FixtureSource;

require __DIR__.'/../vendor/autoload.php';

$source = new FixtureSource(getenv('FOOD_SOURCE_MOCK_MODE') ?: FixtureSource::MODE_OK);

$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
/** @var array<string, string> $query */
$query = $_GET;

$response = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? $source->handle($uri, $query)
    : Response::ofJson(405, ['error' => ['code' => 'bad_request', 'message' => 'GET only.']]);

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header("{$name}: {$value}");
}
echo $response->body;
