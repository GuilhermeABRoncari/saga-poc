<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Activities\ServiceA\ServiceAActivities;
use Temporal\WorkerFactory;

$factory = WorkerFactory::create();
$worker = $factory->newWorker('service-a');
$worker->registerActivity(ServiceAActivities::class);
$factory->run();
