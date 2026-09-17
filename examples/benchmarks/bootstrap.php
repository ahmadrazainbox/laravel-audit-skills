<?php

declare(strict_types=1);

/**
 * Shared helpers for the benchmarks. No framework, no Composer - the only
 * requirement is PHP with pdo_sqlite, so anyone can reproduce the numbers.
 */

function arg_value(string $name, int $default): int
{
    foreach ($GLOBALS['argv'] ?? [] as $i => $token) {
        if ($token === "--{$name}") {
            return (int) ($GLOBALS['argv'][$i + 1] ?? $default);
        }
        if (str_starts_with($token, "--{$name}=")) {
            return (int) substr($token, strlen($name) + 3);
        }
    }

    return $default;
}

/** A PDO handle that counts every statement it executes. */
final class CountingPdo extends PDO
{
    public int $queries = 0;

    public function run(string $sql, array $bindings = []): PDOStatement
    {
        $this->queries++;
        $statement = $this->prepare($sql);
        $statement->execute($bindings);

        return $statement;
    }

    public function reset(): void
    {
        $this->queries = 0;
    }
}

function connect(): CountingPdo
{
    if (! extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "This benchmark needs the pdo_sqlite extension.\n");
        exit(1);
    }

    $pdo = new CountingPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

function human_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return sprintf('%.1f %s', $value, $units[$i]);
}

function heading(string $title): void
{
    printf("\n\033[1m%s\033[0m\n%s\n", $title, str_repeat('-', strlen($title)));
}

function row(string $label, string $value): void
{
    printf("  %-34s %s\n", $label, $value);
}

/**
 * Run $fn and report wall time plus the peak memory it held.
 *
 * memory_get_peak_usage() is a process-wide high-water mark, so it has to be
 * reset before each measurement or the first heavy run masks every later one.
 *
 * @return array{0: mixed, 1: float, 2: int} [result, seconds, peak bytes]
 */
function measure(callable $fn): array
{
    gc_collect_cycles();

    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();                 // PHP >= 8.2
    }

    $before = memory_get_usage();
    $peakBefore = memory_get_peak_usage();
    $start = hrtime(true);

    $result = $fn();

    $seconds = (hrtime(true) - $start) / 1e9;
    $peak = max(memory_get_peak_usage() - $peakBefore, memory_get_usage() - $before, 0);

    return [$result, $seconds, $peak];
}
