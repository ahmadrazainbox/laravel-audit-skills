---
name: laravel-bulk-data
description: Finds Laravel code that loads entire tables into memory - exports, imports, reports and jobs that call all() or get() on unbounded queries - and converts it to the right streaming primitive, choosing between chunkById, cursor, lazy, lazyById and queued batches. Use when the user hits an out-of-memory or allowed-memory-exhausted error, says an export or import times out, asks how to process a large table, or asks about chunk versus cursor versus lazy.
license: MIT
compatibility: Requires read access to a Laravel codebase (Laravel 8 or newer). Uses Grep, Glob and Read. Suggests code changes; applies them only when the user asks.
metadata:
  repository: https://github.com/ahmadraza/laravel-audit-skills
  fixture: examples/demo-app
---

# Laravel bulk data

Find the code that holds a whole table in memory, and replace it with the right
streaming primitive.

The symptom is usually `Allowed memory size of N bytes exhausted` or a request
that dies at 60 seconds. The cause is almost always a collection that was
materialised before anything was written.

## Finding it

Search for:

- `Model::all()` anywhere outside a seeder, a test or a small lookup table;
- `->get()` on a query with no `where`, no `limit` and no `paginate`;
- `->pluck()` on an unbounded query (cheaper than `get()`, still unbounded);
- `->toArray()` or `->map()` on a large collection;
- `foreach` over a query result that writes a file, calls an API, or sends mail;
- `Excel::download` / `fputcsv` / `fwrite` loops fed by a pre-loaded collection;
- jobs and console commands that open with a `get()`.

Then check the table. A `get()` on a 200-row settings table is fine. The
question is always *what does this look like at 100x the current row count*,
and the honest answer for most production tables is "bigger than memory".

## Choosing the primitive

This is the decision the skill exists to get right.

| Situation | Use | Why |
|---|---|---|
| Read-only pass, no writes to the same table | `cursor()` | One row in memory at a time, one query. Cannot be used across a queue boundary. |
| Same as above, want collection methods | `lazy()` | `LazyCollection` — `map`, `filter`, `chunk` all stay lazy. |
| Updating or deleting the rows you iterate | `chunkById()` | Keyset pagination. `chunk()` skips rows when the result set shifts under you. |
| Updating rows, want lazy semantics | `lazyById()` | `lazy()` with the same keyset safety. |
| Work per row is slow (API call, PDF, mail) | queued `Bus::batch()` over `chunkById` | Keeps each job short, retryable and parallel. |
| Pure aggregate — sum, count, group | a single SQL query | Do not iterate at all. `selectRaw` + `groupBy`. |
| Writing a file the user downloads | `response()->streamDownload` + `cursor()` | First byte goes out immediately; nothing accumulates. |

Two rules that matter more than the table:

1. **Never `chunk()` while modifying the chunked column.** `chunk()` pages with
   `OFFSET`. Update or delete rows inside the loop and the offsets shift, so
   roughly half the rows are silently skipped. `chunkById()` pages by primary
   key and is immune. This bug ships constantly and is invisible in testing at
   small row counts.
2. **`cursor()` does not cross a queue boundary.** The generator holds an open
   result set. Serialising it into a job does not work. Chunk into jobs instead.

## Rewriting

The export in the fixture, as a worked example:

```php
// Before - hydrates every order before writing a byte
$orders = Order::all();
return response()->streamDownload(function () use ($orders) {
    $out = fopen('php://output', 'w');
    foreach ($orders as $order) {
        fputcsv($out, [$order->id, $order->customer->name, $order->total_cents / 100]);
    }
}, 'orders.csv');

// After - constant memory, first byte immediately
return response()->streamDownload(function () {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'customer', 'total']);

    Order::query()
        ->with('customer')          // still needed - cursor() does not prevent N+1
        ->cursor()
        ->each(function (Order $order) use ($out) {
            fputcsv($out, [$order->id, $order->customer->name, $order->total_cents / 100]);
        });

    fclose($out);
}, 'orders.csv');
```

Note the `with('customer')`: switching to `cursor()` fixes memory and does
nothing at all about the N+1 in the loop. The two problems are independent and
both have to be fixed. See the `laravel-query-audit` skill.

## Beyond the query

When the query is already streaming and memory still climbs, look for:

- **The query log.** `DB::connection()->enableQueryLog()` is on by default in
  some setups and retains every query. `DB::connection()->disableQueryLog()` in
  long-running commands.
- **Events.** Model events fire per row; listeners that accumulate state grow
  unboundedly. `Model::withoutEvents()` for pure data passes.
- **Debug tooling.** Telescope and Debugbar record every query. Disable them in
  the command or the job.
- **Accumulating output.** Building a string or array of results defeats the
  streaming you just added.
- **Unreleased references.** `unset()` inside the loop where the body keeps a
  reference; PHP will not collect what is still reachable.

## Imports

The mirror image, and the same shape of fix:

- Read the source with a generator (`SplFileObject`, `fgetcsv` in a `while`,
  `LazyCollection::make`), never `file()` or `file_get_contents`.
- Insert with `upsert()` in batches of a few hundred, not one `create()` per
  row — a model instance and its events per row is the slow part.
- Wrap a batch in a transaction, not the whole file.
- For anything over a few hundred thousand rows, suggest the database's own
  bulk loader (`LOAD DATA INFILE`, `COPY`) and say why: it skips PHP entirely.

## Reporting

For each finding give the file and line, the primitive to switch to, the reason
that primitive and not another, and the diff. Where the user can run it, ask for
`memory_get_peak_usage(true)` before and after — a measured number ends the
discussion.

A runnable demonstration is in this skill's repository: at 50,000 rows the
`all()` version holds 22.8 MB against no measurable growth for the streamed
version, and the gap doubles every time the table does.
