<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Activities\ServiceB\ServiceBActivities;
use Temporal\WorkerFactory;

$factory = WorkerFactory::create();
$worker = $factory->newWorker('service-b');
$worker->registerActivity(ServiceBActivities::class);
$factory->run();
