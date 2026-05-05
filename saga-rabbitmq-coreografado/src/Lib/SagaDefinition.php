<?php

declare(strict_types=1);

namespace Mobilestock\SagaCoreografada;

abstract class SagaDefinition
{
    abstract public function register(SagaListener $listener): void;
}
