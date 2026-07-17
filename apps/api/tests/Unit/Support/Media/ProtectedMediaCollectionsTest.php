<?php

use App\Identity\Models\DataSubjectRequest;
use App\Orders\Models\Ticket;
use App\Reporting\Models\Export;

it('stores every credential-bearing or PII export collection on the protected disk', function (): void {
    $protectedDisk = config()->string('media.protected_disk');

    expect((new Ticket)->getMediaCollection('ticket_pdf')?->diskName)->toBe($protectedDisk)
        ->and((new Export)->getMediaCollection('export_file')?->diskName)->toBe($protectedDisk)
        ->and((new DataSubjectRequest)->getMediaCollection('data_subject_export')?->diskName)->toBe($protectedDisk)
        ->and(config("filesystems.disks.{$protectedDisk}.visibility"))->toBe('private');
});

it('keeps the package ceiling separate from the validated HTTP upload ceiling', function (): void {
    expect(config()->integer('media.max_upload_kb'))->toBeLessThan(PHP_INT_MAX)
        ->and(config()->integer('media-library.max_file_size'))->toBe(PHP_INT_MAX);
});
