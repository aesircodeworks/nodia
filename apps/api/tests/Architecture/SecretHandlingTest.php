<?php

use Symfony\Component\Finder\Finder;

/*
 * Stage-12 plan, Slice 6 (security sweep): the environment is read in config
 * files and nowhere else, and every committed .env.example carries placeholders
 * rather than credentials. Both are static repo-shape guards, so they run in the
 * Architecture suite with no database and no application boot.
 *
 * The runtime scan below deliberately stops at the application's own code
 * (app, bootstrap, database, routes, public, artisan) and does not cover tests.
 * The reason the environment must not be read outside config/ is config caching:
 * once `php artisan config:cache` runs, the helper returns null for anything the
 * cached config did not already resolve (system-design 14.1, ADR 017's env-only
 * configuration posture). No test process ever caches config, and the two reads
 * the harness does make (Tests\Support\PostgresTestDatabase building its
 * PostgreSQL connection, and the Redis queue name in the outbox delivery test)
 * happen before there is any config entry to read instead.
 */

$repositoryRoot = dirname(__DIR__, 4);
$apiRoot = dirname(__DIR__, 2);

test('the environment is read only in config files', function () use ($apiRoot): void {
    // Matches the bare helper and the facade behind it, while leaving getenv,
    // $app->environment(), and any $this->env property alone.
    $forbidden = '/(?<![\$>\w\\\\])env\s*\(|(?<![\w\\\\])Env::/';

    $violations = [];

    $files = Finder::create()
        ->files()
        ->in([
            $apiRoot.'/app',
            $apiRoot.'/bootstrap',
            $apiRoot.'/database',
            $apiRoot.'/routes',
            $apiRoot.'/public',
        ])
        ->append([$apiRoot.'/artisan'])
        ->name(['*.php', 'artisan']);

    foreach ($files as $file) {
        foreach (explode("\n", $file->getContents()) as $index => $line) {
            if (preg_match($forbidden, $line) === 1) {
                $violations[] = sprintf('%s:%d %s', $file->getRelativePathname(), $index + 1, trim($line));
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "Application code must read the environment only in config/ files and reach it everywhere else through config(); a cached config makes the environment helper return null at runtime. Violations:\n%s",
        implode("\n", $violations),
    ));
});

test('committed env examples carry placeholders rather than credentials', function () use ($repositoryRoot): void {
    /*
     * The local Compose stack's own published defaults, not secrets: each value
     * below is the literal fallback infra/compose/docker-compose.yml already
     * ships inline (${DB_PASSWORD:-nodia}, ${MINIO_ROOT_PASSWORD:-nodia12345}),
     * so the stack stands up identically with the file absent. Pinned by exact
     * value, so replacing one with a real credential fails this test.
     */
    $localStackDefaults = [
        '.env.example:DB_PASSWORD' => 'nodia',
        '.env.example:MINIO_ROOT_PASSWORD' => 'nodia12345',
    ];

    $secretShapedKey = '/(?:KEY|SECRET|TOKEN|PASSWORD|PASSPHRASE|CREDENTIALS?|PRIVATE|SALT|SIGNATURE|DSN)/';

    // A TTL, a port, or a feature flag can carry a secret-shaped name (
    // PASSPORT_ACCESS_TOKEN_TTL_MINUTES) without carrying a secret value.
    $placeholder = '/^(?:|null|true|false|\d+)$/i';

    // Credential shapes that are never acceptable in an example file whatever
    // the key is named, so a real secret cannot hide behind an innocent name.
    $credentialShaped = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/^eyJ[A-Za-z0-9_-]{8,}\./',
        '/^AKIA[0-9A-Z]{16}$/',
        '/^(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}$/',
        '/^gh[pousr]_[A-Za-z0-9]{20,}$/',
        '/^xox[baprs]-[A-Za-z0-9-]{10,}$/',
        '/^[A-Za-z0-9+\/=_-]{32,}$/',
    ];

    $examples = Finder::create()
        ->files()
        ->in($repositoryRoot)
        ->exclude(['node_modules', 'vendor', '.git'])
        ->ignoreDotFiles(false)
        ->name('.env.example');

    expect(iterator_count($examples))->toBeGreaterThan(0, 'The scan found no .env.example files at all, so it proves nothing.');

    $violations = [];

    foreach ($examples as $file) {
        $path = str_replace($repositoryRoot.'/', '', $file->getRealPath());

        foreach (explode("\n", $file->getContents()) as $index => $line) {
            if (preg_match('/^\s*(?:#|$)/', $line) === 1) {
                continue;
            }

            if (preg_match('/^\s*(?<key>[A-Z0-9_]+)\s*=\s*(?<value>.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $key = $matches['key'];
            $value = trim($matches['value'], " \t\"'");
            $where = sprintf('%s:%d %s', $path, $index + 1, $key);

            foreach ($credentialShaped as $pattern) {
                if (preg_match($pattern, $value) === 1) {
                    $violations[] = $where.' carries a value shaped like a real credential';

                    continue 2;
                }
            }

            if (preg_match($secretShapedKey, $key) !== 1 || preg_match($placeholder, $value) === 1) {
                continue;
            }

            $allowed = $localStackDefaults[$path.':'.$key] ?? null;

            if ($allowed !== $value) {
                $violations[] = $where.' must be blank or a placeholder, or be listed as a local stack default';
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "Committed .env.example files document which variables exist; they never carry a real credential (system-design 14.1). Violations:\n%s",
        implode("\n", $violations),
    ));
});
