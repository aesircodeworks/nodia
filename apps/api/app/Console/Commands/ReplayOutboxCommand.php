<?php

namespace App\Console\Commands;

use App\Support\Outbox\OutboxReplay;
use Illuminate\Console\Command;

/**
 * Rebuild a projection by rescanning the outbox past the stability window
 * (system-design 9.1). Stage 12 adds support-safe audited operational
 * wrappers; this command is the underlying primitive only.
 */
class ReplayOutboxCommand extends Command
{
    protected $signature = 'outbox:replay
                            {subscriber : Registered outbox subscriber name}
                            {--from-sequence= : Inclusive sequence to start from}';

    protected $description = 'Replay outbox events past the stability window to a subscriber in sequence order';

    public function handle(OutboxReplay $replay): int
    {
        $subscriber = (string) $this->argument('subscriber');
        $fromOption = $this->option('from-sequence');
        $fromSequence = ($fromOption !== null && $fromOption !== '')
            ? (int) $fromOption
            : null;

        $count = $replay->replay($subscriber, $fromSequence);

        $this->info("Replayed {$count} outbox event(s) to [{$subscriber}].");

        return self::SUCCESS;
    }
}
