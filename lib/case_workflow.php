<?php

function caseWorkflowCan(string $role, string $permission): bool
{
    $permissions = [
        'OFFICER' => ['triage', 'assign', 'priority', 'findings', 'refer', 'resolve'],
        'CAPTAIN' => ['triage', 'assign', 'priority', 'findings', 'refer', 'resolve', 'close', 'reopen', 'approve_referral'],
        'ADMIN' => ['triage', 'assign', 'priority', 'findings', 'refer', 'resolve', 'close', 'reopen', 'approve_referral'],
        'SYSTEM_ADMIN' => ['triage', 'assign', 'priority', 'findings', 'refer', 'resolve', 'close', 'reopen', 'approve_referral'],
    ];

    return in_array($permission, $permissions[strtoupper(trim($role))] ?? [], true);
}

function caseWorkflowAllowsTransition(string $from, string $to): bool
{
    $transitions = [
        'PENDING' => ['IN_PROGRESS', 'REJECTED'],
        'UNDER_REVIEW' => ['IN_PROGRESS', 'REJECTED'],
        'CRITICAL' => ['IN_PROGRESS', 'REJECTED'],
        'IN_PROGRESS' => ['DISPATCHED', 'FOR_RESOLUTION', 'RESOLVED', 'CLOSED'],
        'DISPATCHED' => ['IN_PROGRESS', 'DISPATCHED', 'FOR_RESOLUTION', 'RESOLVED', 'CLOSED'],
        'FOR_RESOLUTION' => ['IN_PROGRESS', 'FOR_RESOLUTION', 'RESOLVED', 'CLOSED'],
        'RESOLVED' => ['CLOSED'],
        'CLOSED' => ['IN_PROGRESS'],
        'REJECTED' => [],
    ];

    return in_array(strtoupper(trim($to)), $transitions[strtoupper(trim($from))] ?? [], true);
}
