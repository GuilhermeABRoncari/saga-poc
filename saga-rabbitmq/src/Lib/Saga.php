<?php

declare(strict_types=1);

namespace Mobilestock\Saga;

abstract class Saga
{
    /** @return Step[] */
    abstract public function definition(): array;

    public function name(): string
    {
        return static::class;
    }
}
