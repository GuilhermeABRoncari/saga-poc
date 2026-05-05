<?php

declare(strict_types=1);

namespace Mobilestock\SagaCoreografada;

final class SagaRegistry
{
    /** @var list<SagaDefinition> */
    private array $definitions = [];

    public function add(SagaDefinition $definition): self
    {
        $this->definitions[] = $definition;
        return $this;
    }

    /** @return iterable<SagaDefinition> */
    public function all(): iterable
    {
        return $this->definitions;
    }
}
