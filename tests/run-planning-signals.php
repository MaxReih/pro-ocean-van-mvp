<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Domain/WorkState.php';
require_once dirname(__DIR__) . '/src/Service/PlanningSignalService.php';

use ProOceanVan\Service\PlanningSignalService;

function expect_signal(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "OK: {$message}\n";
}

$appointments = [
    '2026-08-03' => ['appointment_date' => '2026-08-03', 'state_code' => 'SL'],
    '2026-08-05' => ['appointment_date' => '2026-08-05', 'state_code' => 'RP'],
];
$requests = [
    [
        'id' => 1,
        'work_state' => 'new',
        'request_mode' => 'date_range',
        'desired_date_from' => '2026-08-03',
        'desired_date_to' => '2026-08-07',
        'possible_weekdays' => 'mon,tue,wed,thu,fri',
        'state_code' => 'BW',
    ],
    [
        'id' => 2,
        'work_state' => 'in_review',
        'request_mode' => 'specific_date',
        'specific_requested_date' => '2026-08-07',
        'state_code' => 'BW',
    ],
    [
        'id' => 3,
        'work_state' => 'new',
        'request_mode' => 'date_range',
        'desired_date_from' => '2026-08-01',
        'desired_date_to' => '2026-08-31',
        'planned_suggestion_date' => '2026-08-11',
        'possible_weekdays' => '',
        'state_code' => 'BY',
    ],
    [
        'id' => 99,
        'work_state' => 'new',
        'request_mode' => 'date_range',
        'desired_date_from' => '2026-08-03',
        'desired_date_to' => '2026-08-14',
        'possible_weekdays' => '',
        'state_code' => 'BW',
    ],
];

$service = new PlanningSignalService();
$profile = $service->build($appointments, $requests, '2026-08-01', '2026-08-31', 99);
$clustered = $service->forDate($profile, '2026-08-06', 'BW');
$otherState = $service->forDate($profile, '2026-08-11', 'BW');
$empty = $service->forDate($profile, '2026-08-20', 'BW');

expect_signal($profile['demand_state_count'] === 2, 'open requests detect demand from multiple federal states');
expect_signal($clustered['week_load'] === 4, 'confirmed stops and open requests contribute to weekly load');
expect_signal($clustered['same_state_count'] === 2, 'requests from the same federal state form a regional cluster');
expect_signal(in_array('WEEK_FILLING', $clustered['codes'], true), 'an active week receives a fill signal');
expect_signal(in_array('SAME_STATE_CLUSTER', $clustered['codes'], true), 'same-state demand receives a regional signal');
expect_signal(in_array('MULTI_STATE_AGGREGATION', $clustered['codes'], true), 'multi-state demand activates state aggregation');
expect_signal(in_array('STATE_BLOCK_CONFLICT', $otherState['codes'], true), 'a different-state week is marked as a regional conflict');
expect_signal($clustered['score_adjustment'] < $otherState['score_adjustment'], 'a matching regional week outranks a different-state week');
expect_signal($empty['score_adjustment'] === 0.0, 'an empty week has no artificial planning advantage');
expect_signal($empty['demand_count'] === 0, 'a flexible request counts only in its preferred suggestion week');

echo "Planning-signal checks passed.\n";
