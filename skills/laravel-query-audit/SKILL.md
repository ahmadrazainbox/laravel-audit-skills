---
name: laravel-query-audit
description: Audits an existing Laravel codebase for N+1 queries and Eloquent performance problems, then reports each finding with its file, line, cost and fix. Use when the user says a page or endpoint is slow, mentions N+1 or lazy loading, asks to optimize queries or Eloquent, asks why something takes so long, or wants a performance review before a release.
license: MIT
compatibility: Requires read access to a Laravel codebase (Laravel 8 or newer). Uses Grep, Glob and Read. Runs no migrations and writes no files unless the user asks for fixes to be applied.
metadata:
  repository: https://github.com/ahmadraza/laravel-audit-skills
  fixture: examples/demo-app
---

# Laravel query audit

Find the queries that make a Laravel application slow, and say what to do about
each one.

This is an audit, not a rewrite. The output is a findings report. Only change
code when the user asks for it, and then one finding at a time.

## How to run the audit

Work in three passes. Do not skip to reading files at random — the point of the
first pass is to spend context only where there is something to find.

### Pass 1 — locate candidates

Run the scanner. It does the mechanical work — statement joining so a chain
broken across five lines is judged as one thing, brace-depth tracking so
"inside a loop" means inside a loop, and string-literal stripping so `{$var}`
interpolation does not corrupt either.

```bash
./scripts/scan-queries.sh /path/to/laravel-app
./scripts/scan-queries.sh . --json            # machine-readable
./scripts/scan-queries.sh . --rules Q-ALL,Q-LOOP-QUERY
```

It excludes `vendor/`, `tests/`, seeders and factories — a seeder calling
`Model::all()` is doing its job.

Every hit is a **candidate**, not a finding. The rules:

| Rule | What it matches |
|---|---|
| `Q-ALL` | `Model::all()` — the whole table, no pagination |
| `Q-UNBOUNDED-GET` | `->get()` with no `where`, `limit` or `paginate` |
| `Q-LOOP-QUERY` | a query executed inside a loop body |
| `Q-LOOP-RELATION` | a relation chain read inside a loop body |
| `Q-COUNT-HYDRATE` | `->relation->count()` — loading rows to produce an integer |
| `Q-PHP-AGGREGATE` | summing or grouping in PHP that SQL could `GROUP BY` |
| `Q-BLADE-RELATION` | a relation walked inside `@foreach` |
| `Q-BLADE-NESTED-LOOP` | a nested `@foreach` over a relation of the outer row |
| `Q-MISSING-INDEX` | an unindexed column that is a foreign key, or is filtered/sorted on |

[references/n-plus-one-patterns.md](references/n-plus-one-patterns.md) has the
full catalogue: what each rule matches, how to confirm it, and the fix.
[references/indexing-rules.md](references/indexing-rules.md) covers when an
index is the fix, composite column order, and when adding one makes things
worse.

If the scanner will not run — no bash, an unusual layout — fall back to
searching for those patterns by hand with Grep. The rules table above is the
search list.

### Pass 2 — confirm each candidate

Read the surrounding code and decide. A candidate is a real finding only if all
of these hold:

- the loop actually runs more than a handful of times in production (a settings
  page over five rows is not an N+1 worth reporting);
- the relation or query is genuinely not already loaded — check for `with()`,
  `load()`, `withCount()`, a global scope, or `$with` on the model;
- the fix is not already applied somewhere upstream.

Dismiss the rest silently. Do not pad the report.

For each confirmed finding, work out the query count as a formula: `1 + N`,
`1 + 2N`, `1 + N + NM`. That number is what makes the finding persuasive.

### Pass 3 — report

One table, worst first, then the details. Use this shape:

```
## Findings

| # | Severity | File | Problem |
|---|----------|------|---------|
| 1 | high     | app/Http/Controllers/PostController.php:14 | 1 + 2N queries on the posts index |

### 1. Posts index issues 1 + 2N queries

app/Http/Controllers/PostController.php:14 loads every post with Post::all(),
and resources/views/posts/index.blade.php then reads $post->author and
$post->comments for each row. At 500 posts that is 1,001 queries.

Fix:

    -$posts = Post::all();
    +$posts = Post::with('author')->withCount('comments')->paginate(25);

and in the view:

    -{{ $post->comments->count() }}
    +{{ $post->comments_count }}
```

Rules for the report:

- Severity is about impact, not elegance. `high` = grows with table size on a
  hot path. `medium` = grows but on a cold path, or bounded. `low` = wasteful
  but constant.
- Always give the fix as a diff against the real lines, never as prose advice.
- If a fix changes behaviour — `paginate()` where the view expected everything,
  `withCount` where the code needed the models — say so explicitly.
- End with what you checked and found clean. An audit that only lists problems
  gives the reader no sense of coverage.

## The fixes, in order of preference

1. **`with()`** — the relation is needed for every row.
2. **`withCount()`** — only the number is needed, never the rows.
3. **`load()`** — the collection already exists and cannot be re-queried.
4. **`chunkById()` / `cursor()` / `lazy()`** — the set is large and processed
   once. See the `laravel-bulk-data` skill.
5. **A single aggregate query** — `selectRaw` with `GROUP BY` beats accumulating
   in PHP.
6. **An index** — when the query itself is right but the column is unindexed.

Prefer `withCount()` over `with()` whenever only a count is used: `with()` still
hydrates every child model.

## Traps

- **`$with` on the model** eager loads globally. It removes N+1s and quietly
  adds cost to every query that does not need the relation. Flag it when you
  see it used to paper over one slow page.
- **`with()` inside a loop** is still N+1. Look at where the query is built,
  not where the relation is read.
- **Pagination hides it.** Twenty-five rows per page makes 51 queries look fine
  in development and fall over on an export or an API client asking for 1,000.
- **`whereHas` without `with`** filters correctly and still lazy-loads on
  access. They solve different problems.
- **Accessors that query.** An accessor calling `$this->relation` turns every
  attribute read into a query. Grep accessors before clearing a model.
- **Polymorphic relations** need `morphWith()`; a plain `with()` on a `morphTo`
  loads each type separately.

## Verifying a fix

Proof beats assertion. When the user can run the app, ask them to wrap the
route in a query log and report the count before and after:

```php
DB::enableQueryLog();
// ... hit the code path ...
logger()->info('queries: '.count(DB::getQueryLog()));
```

If the project has Telescope, Debugbar or Nightwatch installed, use it — it is
already measuring this.

A worked before/after on a fixture with these exact flaws lives in
`examples/demo-app` and `examples/benchmarks` in this skill's repository, and
`examples/reports/query-audit.md` is this skill's real output on it. At 500
posts the pattern above measures 1,001 queries against 3.
