<?php

use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;

const COUNTER_ID = '019797f0-0000-7000-8000-0000000000c1';
const WORKERS = 8;
const ITERATIONS_PER_WORKER = 50;

beforeEach(function (): void {
    DB::statement('drop table if exists concurrency_counters');
    DB::statement(<<<'SQL'
        create table concurrency_counters (
            id uuid primary key,
            quantity integer not null,
            taken integer not null default 0
        )
        SQL);
});

afterEach(function (): void {
    DB::statement('drop table if exists concurrency_counters');
});

function seedCounter(int $quantity): void
{
    DB::insert(
        'insert into concurrency_counters (id, quantity, taken) values (?, ?, 0)',
        [COUNTER_ID, $quantity],
    );
}

function currentTaken(): int
{
    return (int) DB::scalar('select taken from concurrency_counters where id = ?', [COUNTER_ID]);
}

it('produces real contention: read-then-write increments lose updates', function (): void {
    $attempts = WORKERS * ITERATIONS_PER_WORKER;

    seedCounter($attempts);

    ParallelRunner::run(WORKERS, function (PDO $pdo): int {
        $read = $pdo->prepare('select taken from concurrency_counters where id = ?');
        $write = $pdo->prepare('update concurrency_counters set taken = ? where id = ?');

        for ($i = 0; $i < ITERATIONS_PER_WORKER; $i++) {
            $read->execute([COUNTER_ID]);
            $taken = (int) $read->fetchColumn();

            usleep(random_int(100, 1500));

            $write->execute([$taken + 1, COUNTER_ID]);
        }

        return ITERATIONS_PER_WORKER;
    });

    // If this assertion ever fails, the runner is not producing genuine
    // contention and the whole suite proves nothing; strengthen the runner
    // (more workers or iterations), never weaken the assertion.
    expect(currentTaken())->toBeLessThan($attempts);
});

it('never oversells: conditional updates checked by affected rows account exactly', function (): void {
    $quantity = 25;

    seedCounter($quantity);

    $granted = ParallelRunner::run(WORKERS, function (PDO $pdo): int {
        $claim = $pdo->prepare(
            'update concurrency_counters set taken = taken + 1 where id = ? and taken < quantity',
        );

        $successes = 0;

        for ($i = 0; $i < ITERATIONS_PER_WORKER; $i++) {
            $claim->execute([COUNTER_ID]);
            $successes += $claim->rowCount();
        }

        return $successes;
    });

    expect(WORKERS * ITERATIONS_PER_WORKER)->toBeGreaterThan($quantity)
        ->and(array_sum($granted))->toBe($quantity)
        ->and(currentTaken())->toBe($quantity);
});
