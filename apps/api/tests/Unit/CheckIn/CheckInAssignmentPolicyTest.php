<?php

use App\CheckIn\Policies\CheckInAssignmentPolicy;
use App\Identity\Capability;

/*
 * Stage-09 plan, Authorization semantics paragraph: manifest, keys, scan,
 * and batch endpoints require checkin.scan plus an assignment row for the
 * target event; checkin.manage bypasses the assignment requirement.
 * Evaluated on capabilities alone, never role names.
 */

it('allows a caller holding checkin.scan who is assigned to the event', function (): void {
    expect(CheckInAssignmentPolicy::allows([Capability::CheckinScan->value], assigned: true))->toBeTrue();
});

it('denies a caller holding checkin.scan who is not assigned to the event', function (): void {
    expect(CheckInAssignmentPolicy::allows([Capability::CheckinScan->value], assigned: false))->toBeFalse();
});

it('allows a caller holding checkin.manage even when not assigned to the event', function (): void {
    expect(CheckInAssignmentPolicy::allows([Capability::CheckinManage->value], assigned: false))->toBeTrue();
});

it('denies a caller holding neither checkin.scan nor checkin.manage', function (): void {
    expect(CheckInAssignmentPolicy::allows(['events.view'], assigned: false))->toBeFalse()
        ->and(CheckInAssignmentPolicy::allows([], assigned: false))->toBeFalse();
});
