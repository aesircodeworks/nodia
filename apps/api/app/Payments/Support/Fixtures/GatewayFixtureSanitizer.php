<?php

namespace App\Payments\Support\Fixtures;

/**
 * Guards the recorded-fixture directory against leaked secret material
 * (stage-08d plan, Slice 2). `scan()` is run permanently in CI over
 * tests/Fixtures/gateways; `redact()` is applied by the record command
 * before a fixture is written, replacing recognized secret shapes with a
 * placeholder so the guard passes on freshly recorded fixtures too.
 */
final class GatewayFixtureSanitizer
{
    /**
     * @var array<string, string>
     */
    private const PATTERNS = [
        'bearer_token' => '/Bearer\s+[A-Za-z0-9\-_.]{8,}/i',
        'known_credential' => '/\b(sk|pk|rk)_(live|test)_[A-Za-z0-9]{8,}\b|\bAKIA[0-9A-Z]{16}\b/',
        'pan_like_digit_run' => '/\b\d{13,19}\b/',
    ];

    /**
     * @return list<array{file: string, pattern: string, snippet: string}>
     */
    public function scan(string $directory): array
    {
        $violations = [];

        foreach ($this->filesRecursive($directory) as $file) {
            $violations = [...$violations, ...$this->scanFile($file)];
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $exchange
     * @return array<string, mixed>
     */
    public function redact(array $exchange): array
    {
        $json = json_encode($exchange);

        if ($json === false) {
            return $exchange;
        }

        foreach (self::PATTERNS as $pattern) {
            $json = preg_replace($pattern, '[REDACTED]', $json);
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : $exchange;
    }

    /**
     * @return list<array{file: string, pattern: string, snippet: string}>
     */
    private function scanFile(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            return [];
        }

        $violations = [];

        foreach (self::PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $contents, $matches) === 1) {
                $violations[] = [
                    'file' => $file,
                    'pattern' => $name,
                    'snippet' => $matches[0],
                ];
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function filesRecursive(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'json') {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }
}
