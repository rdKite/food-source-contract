<?php

declare(strict_types=1);

namespace FoodSourceContract;

/**
 * The contract version and the paths to its machine-readable parts.
 */
final class Contract
{
    public const string VERSION = '1.1';

    /** Every value status (CONTRACT.md "Status"). */
    public const array STATUSES = [
        'measured', 'calculated', 'estimated', 'unspecified', 'partial',
        'trace', 'missing', 'not_collected',
    ];

    /** Statuses whose `value` is null — exactly these. */
    public const array VALUELESS_STATUSES = ['trace', 'missing', 'not_collected'];

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function specPath(): string
    {
        return self::root().'/spec/openapi.yaml';
    }

    public static function registryPath(): string
    {
        return self::root().'/registry/nutrients.json';
    }

    /** The illustrative fixture a consumer's fixture driver serves. */
    public static function fixturePath(): string
    {
        return self::root().'/fixtures/bls.json';
    }
}
