<?php

namespace Tests\Concurrency\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Spatie\Fork\Fork;

/**
 * Runs a task in N forked worker processes against real PostgreSQL.
 *
 * Each worker opens its own PDO connection (a forked child inherits the
 * parent's connection socket, and two processes sharing one corrupts the
 * wire protocol), then blocks on a start barrier so all workers hit the
 * database at the same instant, which is what makes the contention real.
 */
final class ParallelRunner
{
    private const START_BARRIER_DELAY_SECONDS = 0.25;

    /**
     * @template TResult
     *
     * @param  callable(PDO): TResult  $task
     * @return list<TResult>
     */
    public static function run(int $workers, callable $task): array
    {
        return self::runEach(...array_fill(0, $workers, $task));
    }

    /**
     * Same barrier and connection discipline, one distinct task per worker,
     * for races whose contenders differ (two tenants registering one
     * domain, two domains racing for primary).
     *
     * @template TResult
     *
     * @param  callable(PDO): TResult  ...$tasks
     * @return list<TResult>
     */
    public static function runEach(callable ...$tasks): array
    {
        $config = self::connectionConfig();

        DB::purge();

        $startAt = microtime(true) + self::START_BARRIER_DELAY_SECONDS;

        $results = Fork::new()->run(...array_map(
            fn (callable $task): callable => function () use ($config, $task, $startAt): mixed {
                $pdo = self::connect($config);

                self::awaitStart($startAt);

                return $task($pdo);
            },
            $tasks,
        ));

        return array_values($results);
    }

    /**
     * @return array{host: string, port: string|int, database: string, username: string, password: string}
     */
    private static function connectionConfig(): array
    {
        $config = config()->array('database.connections.'.config()->string('database.default'));

        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('The parallel runner needs a pgsql default connection.');
        }

        /** @var array{host: string, port: string|int, database: string, username: string, password: string} $config */
        return $config;
    }

    /**
     * @param  array{host: string, port: string|int, database: string, username: string, password: string}  $config
     */
    private static function connect(array $config): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $config['database'],
        );

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private static function awaitStart(float $startAt): void
    {
        while (true) {
            $remaining = $startAt - microtime(true);

            if ($remaining <= 0) {
                return;
            }

            usleep((int) min($remaining * 1_000_000, 5_000));
        }
    }
}
