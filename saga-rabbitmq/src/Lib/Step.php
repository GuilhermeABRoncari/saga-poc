<?php

declare(strict_types=1);

namespace Mobilestock\Saga;

final class Step
{
    public function __construct(
        public readonly string $name,
        public readonly string $target,
        public readonly string $command,
        public readonly ?string $compensation = null,
    ) {}
}
