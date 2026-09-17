# benchmarks

Zero-dependency PHP scripts that put a number on the flaws planted in
[`../demo-app`](../demo-app). No Composer, no framework — PHP with `pdo_sqlite`
is the whole requirement, so anyone can reproduce these on any machine and in
CI.

```bash
php prove-n-plus-one.php   --rows 500
php prove-memory-blowup.php --rows 25000
```

Both scripts assert that the slow version and the fast version produce
**identical output** before reporting anything. A benchmark that quietly
compares two different workloads is worse than no benchmark.

## What each one shows

**`prove-n-plus-one.php`** reproduces `PostController@index` feeding
`posts/index.blade.php`: one query for posts, then an author lookup and a
comment count per row. Then it does the same work with the `IN (...)` +
`GROUP BY` pair that `with()` and `withCount()` generate.

At 500 posts: **1,001 queries → 3**, and 84 ms → 2 ms.

That ratio is the *floor*, not the ceiling. SQLite in memory is the friendliest
possible environment for the naive version — there is no network. Point the same
pattern at MySQL or Postgres over a socket and each of those 1,001 queries also
pays a round trip.

**`prove-memory-blowup.php`** reproduces `ReportController@export`:
`Order::all()` before writing a byte, versus streaming a row at a time the way
`cursor()` and `lazy()` do. It runs at two row counts, because the number that
matters is not a ratio but a shape:

| rows | `Order::all()` | `->cursor()` |
|---|---|---|
| 25,000 | 11.4 MB | no measurable growth |
| 50,000 | 22.8 MB | no measurable growth |

One column tracks the table size; the other does not move. Extrapolated to a
million orders that is roughly **455 MB** held in memory to write a CSV — and
that is raw PDO rows. Eloquent hydrates each one into an `Order` object on top.

## Reading the numbers honestly

These are synthetic. The row shapes are narrow, the database is local, and
there is no application code between the query and the output. They are
intended to demonstrate *direction and shape* — linear versus constant, 1,001
versus 3 — not to predict your production latency. Where a skill reports a
finding, it should point at the pattern and the fix, not quote these figures as
though they were measured on your app.
