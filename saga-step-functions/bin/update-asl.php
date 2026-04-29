<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Aws\Sfn\SfnClient;

$sfn = new SfnClient([
    'region' => 'us-east-1',
    'version' => 'latest',
    'endpoint' => 'http://localstack:4566',
    'credentials' => ['key' => 'test', 'secret' => 'test'],
]);

$config = json_decode(file_get_contents('/app/storage/sfn-config.json'), true, 512, JSON_THROW_ON_ERROR);
$arns = $config['activities'];

// V2: insere step audit_log entre reserveStock e chargeCredit (analog to T1.1)
$asl = [
    'Comment' => 'ActivateStoreSaga V2 - com audit_log adicional após reserveStock',
    'StartAt' => 'ReserveStock',
    'States' => [
        'ReserveStock' => [
            'Type' => 'Task',
            'Resource' => $arns['saga-reserve-stock'],
            'ResultPath' => '$.reserve',
            'TimeoutSeconds' => 60,
            'Next' => 'AuditLog',
            'Catch' => [['ErrorEquals' => ['States.ALL'], 'ResultPath' => '$.error', 'Next' => 'FailReserveStock']],
        ],
        'AuditLog' => [
            'Type' => 'Pass',
            'ResultPath' => '$.audit',
            'Result' => ['audit_inserted' => true],
            'Next' => 'ChargeCredit',
        ],
        'ChargeCredit' => [
            'Type' => 'Task',
            'Resource' => $arns['saga-charge-credit'],
            'ResultPath' => '$.charge',
            'TimeoutSeconds' => 60,
            'Next' => 'ConfirmShipping',
            'Catch' => [['ErrorEquals' => ['States.ALL'], 'ResultPath' => '$.error', 'Next' => 'ReleaseStockOnly']],
        ],
        'ConfirmShipping' => [
            'Type' => 'Task',
            'Resource' => $arns['saga-confirm-shipping'],
            'ResultPath' => '$.shipping',
            'TimeoutSeconds' => 60,
            'Next' => 'Success',
            'Catch' => [['ErrorEquals' => ['States.ALL'], 'ResultPath' => '$.error', 'Next' => 'RefundCredit']],
        ],
        'RefundCredit' => [
            'Type' => 'Task',
            'Resource' => $arns['saga-refund-credit'],
            'ResultPath' => '$.refund',
            'TimeoutSeconds' => 60,
            'Next' => 'ReleaseStock',
        ],
        'ReleaseStockOnly' => [
            'Type' => 'Task',
            'Resource' => $arns['saga-release-stock'],
            'ResultPath' => '$.release',
            'TimeoutSeconds' => 60,
            'Next' => 'Compensated',
        ],
        'ReleaseStock' => [
            'Type' => 'Task',
            'Resource' => $arns['saga-release-stock'],
            'ResultPath' => '$.release',
            'TimeoutSeconds' => 60,
            'Next' => 'Compensated',
        ],
        'Compensated' => ['Type' => 'Fail', 'Error' => 'Compensated', 'Cause' => 'Saga compensated successfully'],
        'FailReserveStock' => ['Type' => 'Fail', 'Error' => 'ReserveStockFailed'],
        'Success' => ['Type' => 'Succeed'],
    ],
];

$result = $sfn->updateStateMachine([
    'stateMachineArn' => $config['state_machine_arn'],
    'definition' => json_encode($asl, JSON_UNESCAPED_SLASHES),
]);
echo "[update-asl] V2 published; updateDate=" . $result['updateDate']->format(DATE_ATOM) . "\n";
