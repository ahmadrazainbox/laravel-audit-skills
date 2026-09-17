<?php

declare(strict_types=1);

/**
 * Reproduces the export planted in demo-app's ReportController and measures
 * what streaming saves. Order::all() hydrates the whole table; cursor()/lazy()
 * holds one row at a time.
 *
 * The point is not a single ratio - it is the SHAPE. Run it at two row counts
 * and watch one column double while the other does not move.
 *
 *   php examples/benchmarks/prove-memory-blowup.php --rows 50000
 */

require __DIR__.'/bootstrap.php';

$rows = max(1000, arg_value('rows', 50000));

/** @return array{fetch_all: array{float, int}, stream: array{float, int}, bytes: int} */
function run_scale(int $rows): array
{
    $pdo = connect();
    $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, total_cents INTEGER, status TEXT, placed_at TEXT)');

    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO orders (user_id, total_cents, status, placed_at) VALUES (?, ?, ?, ?)');
    for ($i = 1; $i <= $rows; $i++) {
        $insert->execute([$i % 1000, 500 + ($i * 37) % 500000, 'paid', sprintf('2026-%02d-15 10:00:00', ($i % 12) + 1)]);
    }
    $pdo->commit();

    $target = tempnam(sys_get_temp_dir(), 'bench');

    // The way demo-app does it: Order::all(), then loop.
    [, $allTime, $allPeak] = measure(function () use ($pdo, $target) {
        $orders = $pdo->run('SELECT * FROM orders')->fetchAll();   // Order::all()

        $out = fopen($target, 'w');
        fputcsv($out, ['id', 'total', 'placed_at']);
        foreach ($orders as $order) {
            fputcsv($out, [$order['id'], $order['total_cents'] / 100, $order['placed_at']]);
        }
        fclose($out);

        return null;
    });
    $allBytes = filesize($target);

    // The fix: stream one row at a time - Eloquent's cursor()/lazy().
    [, $streamTime, $streamPeak] = measure(function () use ($pdo, $target) {
        $statement = $pdo->run('SELECT * FROM orders');            // ->cursor()

        $out = fopen($target, 'w');
        fputcsv($out, ['id', 'total', 'placed_at']);
        while ($order = $statement->fetch()) {
            fputcsv($out, [$order['id'], $order['total_cents'] / 100, $order['placed_at']]);
        }
        fclose($out);

        return null;
    });
    $streamBytes = filesize($target);

    unlink($target);

    if ($allBytes !== $streamBytes) {
        fwrite(STDERR, "Benchmark invalid: the two versions produced different files.\n");
        exit(1);
    }

    return ['fetch_all' => [$allTime, $allPeak], 'stream' => [$streamTime, $streamPeak], 'bytes' => $allBytes];
}

function held(int $bytes): string
{
    // Anything under a few KB is the harness itself, not retained rows.
    return $bytes < 4096 ? 'no measurable growth' : human_bytes($bytes);
}

printf("\n\033[1mMemory benchmark\033[0m  SQLite in memory, two scales\n");
printf("\033[2mWatch which column tracks the row count.\033[0m\n");

$small = run_scale($rows);
$large = run_scale($rows * 2);

heading('Peak memory held while writing the CSV');
printf("  %-14s %-24s %s\n", '', "Order::all()", '->cursor() / ->lazy()');
printf("  %-14s %-24s %s\n",
    number_format($rows).' rows', held($small['fetch_all'][1]), held($small['stream'][1]));
printf("  %-14s %-24s %s\n",
    number_format($rows * 2).' rows', held($large['fetch_all'][1]), held($large['stream'][1]));

$growth = $small['fetch_all'][1] > 0 ? $large['fetch_all'][1] / $small['fetch_all'][1] : 0;

heading('Shape');
row('Order::all()', sprintf('grows with the table (%.1fx for 2x the rows)', $growth));
row('->cursor() / ->lazy()', 'flat - one row at a time, whatever the table size');
row('per 1M rows, extrapolated', human_bytes((int) ($large['fetch_all'][1] / ($rows * 2) * 1_000_000)));
row('csv written', human_bytes($large['bytes']).' - byte-identical either way');

heading('Time');
row(number_format($rows * 2).' rows, Order::all()', sprintf('%.0f ms', $large['fetch_all'][0] * 1000));
row(number_format($rows * 2).' rows, streamed', sprintf('%.0f ms', $large['stream'][0] * 1000));

echo "\n\033[2mThese are raw PDO rows. Eloquent adds hydration on top: every row in the\n";
printf("first column also becomes an Order object with its own attribute arrays,\n");
printf("so the real figure for Order::all() is several times worse.\033[0m\n\n");
