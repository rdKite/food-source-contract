<?php

declare(strict_types=1);

namespace FoodSourceContract\Conformance;

final readonly class Result
{
    public function __construct(
        public string $id,
        public string $requirement,
        public Outcome $outcome,
        public string $detail = '',
    ) {}

    public function passed(): bool
    {
        return $this->outcome !== Outcome::Failed;
    }
}
