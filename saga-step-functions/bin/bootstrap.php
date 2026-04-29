<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Aws\Sfn\SfnClient;

$sfn = new SfnClient([
    'region' => $_ENV['AWS_REGION'] ?? 'us-east-1',
    'version' => 'latest',
    'endpoint' => $_ENV['AWS_ENDPOINT_URL'] ?? 'http://localstack:4566',
    'credentials' => [
        'key' => $_ENV['AWS_ACCESS_KEY_ID'] ?? 'test',
        'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? 'test',
    ],
]);

$activityNames = ['saga-reserve-stock', 'saga-charge-credit', 'saga-confirm-shipping', 'saga-refund-credit', 'saga-release-stock'];
$arns = [];
foreach ($activityNames as $name) {
    $result = $sfn->createActivity(['name' => $name]);
    $arns[$name] = $result['activityArn'];
    echo "[bootstrap] activity {$name} → {$arns[$name]}\n";
}

$asl = json_decode(file_get_contents(__DIR__ . '/../state-machine.json'), true, 512, JSON_THROW_ON_ERROR);
$replacements = [
    '__ARN_RESERVE_STOCK__' => $arns['saga-reserve-stock'],
    '__ARN_CHARGE_CREDIT__' => $arns['saga-charge-credit'],
    '__ARN_CONFIRM_SHIPPING__' => $arns['saga-confirm-shipping'],
    '__ARN_REFUND_CREDIT__' => $arns['saga-refund-credit'],
    '__ARN_RELEASE_STOCK__' => $arns['saga-release-stock'],
];
$aslJson = json_encode($asl, JSON_UNESCAPED_SLASHES);
foreach ($replacements as $placeholder => $arn) {
    $aslJson = str_replace($placeholder, $arn, $aslJson);
}

try {
    $existing = $sfn->listStateMachines();
    foreach ($existing['stateMachines'] ?? [] as $sm) {
        if ($sm['name'] === 'ActivateStoreSaga') {
            $sfn->deleteStateMachine(['stateMachineArn' => $sm['stateMachineArn']]);
            echo "[bootstrap] deleted existing state machine\n";
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[bootstrap] cleanup warning: {$e->getMessage()}\n");
}

$result = $sfn->createStateMachine([
    'name' => 'ActivateStoreSaga',
    'definition' => $aslJson,
    'roleArn' => 'arn:aws:iam::000000000000:role/StepFunctionsRole',
    'type' => 'STANDARD',
]);
$stateMachineArn = $result['stateMachineArn'];
echo "[bootstrap] state machine → {$stateMachineArn}\n";

$config = [
    'state_machine_arn' => $stateMachineArn,
    'activities' => $arns,
];
file_put_contents('/app/storage/sfn-config.json', json_encode($config, JSON_PRETTY_PRINT));
echo "[bootstrap] config saved to /app/storage/sfn-config.json\n";
