<?php

declare(strict_types=1);

namespace FoodSourceContract\Conformance;

enum Outcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    /** Not applicable: an optional feature the service does not declare. */
    case Skipped = 'skipped';
}
