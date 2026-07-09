<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use RuntimeException;

/**
 * The log fake: swaps the default channel's handlers for a Monolog
 * TestHandler so tests assert emitted entries instead of writing to
 * stdout. A dedicated fake package (timacdonald/log-fake) is not usable
 * here: it pins symfony/var-dumper ^7 while Laravel 13 locks version 8.
 */
final class LogCapture
{
    public static function fake(): TestHandler
    {
        $logger = Log::getLogger();

        if (! $logger instanceof Logger) {
            throw new RuntimeException('The default log channel is not backed by Monolog.');
        }

        $handler = new TestHandler;

        $logger->setHandlers([$handler]);

        return $handler;
    }

    /**
     * @return list<LogRecord>
     */
    public static function entriesNamed(TestHandler $handler, string $message): array
    {
        return array_values(array_filter(
            $handler->getRecords(),
            fn (LogRecord $record): bool => $record->message === $message,
        ));
    }
}
