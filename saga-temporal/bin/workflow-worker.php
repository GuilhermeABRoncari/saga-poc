<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Sagas\ActivateStoreSaga;
use Temporal\WorkerFactory;

$factory = WorkerFactory::create();
$worker = $factory->newWorker('saga-orchestrator');
$worker->registerWorkflowTypes(ActivateStoreSaga::class);
$factory->run();
