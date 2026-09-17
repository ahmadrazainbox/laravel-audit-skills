# Indexing rules

What `Q-MISSING-INDEX` flags, when an index is actually the fix, and when
adding one makes things worse.

The scanner flags a column when it is unindexed **and** either looks like a
foreign key (`*_id`) or is filtered/sorted on somewhere in the application. That
is a candidate. Whether it is a finding depends on the table.

---

## When an index is the fix

An index earns its place when a query filters, joins or sorts on the column and
the table is large enough that a scan costs something.

| Signal | Index |
|---|---|
| `where('column', ...)` on a table over ~10k rows | yes |
| A foreign key used by any relation | almost always |
| `orderBy('column')` with `limit` — a "latest N" query | yes |
| `where A` + `orderBy B` in the same query | composite, `(A, B)` |
| Column used only in the select list | no |
| Boolean or status column where one value covers most rows | usually not — see below |

**Laravel does not index foreign keys for you.** `foreignId('user_id')` creates
a column. `foreignId('user_id')->constrained()` adds the constraint, and MySQL
creates an index behind it — Postgres does **not**. `unsignedBigInteger('user_id')`
alone creates neither. This is the single most common instance of this finding
and it is worth stating explicitly in the report, because the code looks
deliberate.

---

## Composite indexes: order is the whole thing

An index on `(status, placed_at)` serves:

- `where status = ?`
- `where status = ? order by placed_at`
- `where status = ? and placed_at > ?`

and does **not** serve `where placed_at > ?` on its own. The leftmost column
must appear in the predicate, so column order follows usage:

1. Columns matched for equality, most selective first.
2. Then the range column.
3. Then the sort column.

Two single-column indexes are not a substitute. Most engines will use only one
of them per query.

A migration that adds one:
```php
$table->index(['status', 'placed_at']);
```

---

## When *not* to add one

- **Low cardinality.** An index on a boolean, or on a status column where 95%
  of rows are `paid`, will be ignored by the planner for the common value. It
  can still help for the rare value — a partial index (`WHERE status = 'failed'`
  on Postgres) is the right shape there.
- **Write-heavy tables.** Every index is maintained on insert, update and
  delete. On an append-heavy log table, an index nobody reads is pure cost.
- **Narrow tables.** Below a few thousand rows a scan is often cheaper than the
  index lookup plus row fetch.
- **Redundant prefixes.** `(a)` is redundant when `(a, b)` exists. Drop the
  narrower one.
- **Speculatively.** An index that no query uses is cost with no return. If you
  cannot name the query, do not add the index.

---

## Confirming before you report

Ask for the plan rather than asserting it. It is two lines and it ends the
argument:

```php
DB::select('EXPLAIN SELECT * FROM orders WHERE status = ? ORDER BY placed_at', ['paid']);
```

- **MySQL:** `type: ALL` with a large `rows` value means a full scan; `Using
  filesort` alongside it means the sort is unindexed too.
- **Postgres:** `EXPLAIN (ANALYZE, BUFFERS)`; look for `Seq Scan` where you
  expected `Index Scan`.
- **SQLite:** `EXPLAIN QUERY PLAN`; `SCAN TABLE` versus `SEARCH TABLE ... USING
  INDEX`.

Also ask for the row count. A finding that says "unindexed column on a table
with 4 million rows" is actionable; "unindexed column" alone is a guess.

---

## Writing the migration

Adding an index to a large live table locks it on older MySQL and blocks writes.
Say so when the table is big:

```php
// MySQL 5.6+ does this online for most cases, but verify on a copy first.
Schema::table('orders', function (Blueprint $table) {
    $table->index(['status', 'placed_at']);
});
```

On Postgres, offer the non-blocking form, which cannot run inside Laravel's
default transactional migration:

```php
public $withinTransaction = false;

public function up(): void
{
    DB::statement('CREATE INDEX CONCURRENTLY orders_status_placed_at_index ON orders (status, placed_at)');
}
```

Never recommend an index on a large production table without mentioning the
lock. That detail is the difference between a useful audit and an outage.
