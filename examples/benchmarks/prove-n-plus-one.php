<?php

declare(strict_types=1);

/**
 * Reproduces the N+1 pattern planted in demo-app's PostController/index view
 * and measures what eager loading saves.
 *
 *   php examples/benchmarks/prove-n-plus-one.php --rows 500
 */

require __DIR__.'/bootstrap.php';

$rows = max(10, arg_value('rows', 500));
$commentsPerPost = 5;

$pdo = connect();
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');
$pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, body TEXT)');

$pdo->beginTransaction();
for ($i = 1; $i <= $rows; $i++) {
    $pdo->prepare('INSERT INTO users (id, name) VALUES (?, ?)')->execute([$i, "Author {$i}"]);
    $pdo->prepare('INSERT INTO posts (id, user_id, title) VALUES (?, ?, ?)')->execute([$i, $i, "Post {$i}"]);
    for ($c = 0; $c < $commentsPerPost; $c++) {
        $pdo->prepare('INSERT INTO comments (post_id, body) VALUES (?, ?)')->execute([$i, 'Nice post']);
    }
}
$pdo->commit();

printf("\n\033[1mN+1 benchmark\033[0m  %d posts, %d comments each, SQLite in memory\n",
    $rows, $commentsPerPost);
printf("\033[2m(SQLite in memory is the best case for the naive version - over a network\n");
printf("socket to MySQL or Postgres each of these queries also costs a round trip.)\033[0m\n");

// ---------------------------------------------------------------------------
// The way demo-app does it: one query for posts, then two per post in the view.
// ---------------------------------------------------------------------------
$pdo->reset();
[$naiveOut, $naiveTime] = measure(function () use ($pdo) {
    $out = [];
    foreach ($pdo->run('SELECT * FROM posts')->fetchAll() as $post) {
        $author = $pdo->run('SELECT name FROM users WHERE id = ?', [$post['user_id']])->fetch();
        $comments = $pdo->run('SELECT * FROM comments WHERE post_id = ?', [$post['id']])->fetchAll();
        $out[] = ['title' => $post['title'], 'author' => $author['name'], 'comments' => count($comments)];
    }

    return $out;
});
$naiveQueries = $pdo->queries;

// ---------------------------------------------------------------------------
// The fix: with(['author']) + withCount('comments') - three queries, flat.
// ---------------------------------------------------------------------------
$pdo->reset();
[$eagerOut, $eagerTime] = measure(function () use ($pdo) {
    $posts = $pdo->run('SELECT * FROM posts')->fetchAll();

    $authorIds = array_values(array_unique(array_column($posts, 'user_id')));
    $placeholders = implode(',', array_fill(0, count($authorIds), '?'));
    $authors = [];
    foreach ($pdo->run("SELECT id, name FROM users WHERE id IN ({$placeholders})", $authorIds)->fetchAll() as $u) {
        $authors[$u['id']] = $u['name'];
    }

    $counts = [];
    foreach ($pdo->run('SELECT post_id, COUNT(*) AS aggregate FROM comments GROUP BY post_id')->fetchAll() as $c) {
        $counts[$c['post_id']] = (int) $c['aggregate'];
    }

    $out = [];
    foreach ($posts as $post) {
        $out[] = [
            'title' => $post['title'],
            'author' => $authors[$post['user_id']],
            'comments' => $counts[$post['id']] ?? 0,
        ];
    }

    return $out;
});
$eagerQueries = $pdo->queries;

if ($naiveOut !== $eagerOut) {
    fwrite(STDERR, "Benchmark invalid: the two versions produced different output.\n");
    exit(1);
}

heading('Post::all() + relations walked in the view');
row('queries', number_format($naiveQueries));
row('time', sprintf('%.1f ms', $naiveTime * 1000));

heading("with('author')->withCount('comments')");
row('queries', number_format($eagerQueries));
row('time', sprintf('%.1f ms', $eagerTime * 1000));

heading('Difference');
row('queries', sprintf('%s -> %s  (%.0fx fewer)',
    number_format($naiveQueries), number_format($eagerQueries), $naiveQueries / max($eagerQueries, 1)));
row('time', sprintf('%.1f ms -> %.1f ms  (%.1fx faster)',
    $naiveTime * 1000, $eagerTime * 1000, $naiveTime / max($eagerTime, 1e-9)));
row('rows returned', number_format(count($naiveOut)));
echo "\n";
