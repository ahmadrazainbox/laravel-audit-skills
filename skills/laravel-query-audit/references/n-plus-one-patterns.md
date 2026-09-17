# N+1 patterns

The catalogue behind `scripts/scan-queries.sh`. Each rule gives what the scanner
matches, how to confirm it is real, and the fix. Rule IDs are stable — tests and
reports refer to them.

A candidate is confirmed only when all three hold: the loop runs at scale, the
data is genuinely not already loaded, and the fix is not applied upstream.

---

## Q-ALL — `Model::all()`

**Matches:** any `Model::all()` outside seeders, factories and tests.

**Confirm:** check the table. `Setting::all()` over eleven rows is fine.
`Post::all()` on a table that grows with usage is not. The question is never the
current row count, it is the row count in two years.

**Fix:** `paginate()` when the output is a page; `chunkById()` / `cursor()` when
the set is processed once; a `where` when only a subset was ever needed.

`all()` also cannot be eager-loaded — there is no query builder to attach
`with()` to — so it is very often the parent of a second finding in the view.

---

## Q-UNBOUNDED-GET — `->get()` with nothing narrowing it

**Matches:** `->get()` on a statement with no `where`, `limit`, `take`,
`paginate`, `find` or scope.

**Confirm:** the same table-size question as `Q-ALL`. Also check for a global
scope on the model, which may already be narrowing it invisibly.

**Fix:** as `Q-ALL`.

---

## Q-LOOP-QUERY — a query inside a loop body

**Matches:** `->get()`, `->first()`, `->count()`, `->sum()`, `->exists()`,
`->pluck()`, `DB::table(`, `Model::find(`, `Model::where(` occurring while brace
depth is inside a `foreach`, `while`, `for`, `->each()`, `->map()` or
`->filter()` body.

**Confirm:** count the iterations. This is the most expensive pattern in the
catalogue because each iteration is a full round trip, and on a remote database
the network cost dominates the query cost.

**Fix, in order:**

1. The loop is walking a relation → `with()` on the parent query.
2. The loop is looking up rows by key → one `whereIn()` before the loop, keyed
   into an array:
   ```php
   $users = User::whereIn('id', $comments->pluck('user_id'))->get()->keyBy('id');
   foreach ($comments as $comment) {
       $author = $users[$comment->user_id];
   }
   ```
3. The loop is aggregating → one `groupBy` query (see `Q-PHP-AGGREGATE`).

---

## Q-LOOP-RELATION — a relation chain read inside a loop

**Matches:** `$var->relation->attribute` inside a loop body (PHP), or inside
`@foreach` (Blade — see `Q-BLADE-RELATION`).

**Confirm:** find the query that produced the collection. If it has `with()` for
that relation, this is not a finding. Check `$with` on the model too, and any
`load()` call between the query and the loop.

**Fix:** `with('relation')` on the originating query. For a chain two deep, use
dot notation: `with('comments.author')`.

**Trap:** switching a query to `cursor()` or `lazy()` for memory reasons does
**not** fix this, and `with()` on a `cursor()` only eager-loads within each
internal buffer. When both problems are present, chunking with `with()` inside
each chunk is usually the answer.

---

## Q-COUNT-HYDRATE — counting a relation by loading it

**Matches:** `->relation->count()`, `count($model->relation)`.

**Confirm:** check whether the rows themselves are used anywhere nearby. If the
template renders the comments *and* the count, `with()` plus `count()` on the
loaded collection is correct and this is not a finding.

**Fix:** `withCount('comments')`, read as `$post->comments_count`.

Multiple counts in one query:
```php
Post::withCount(['comments', 'likes'])->get();
```
Conditional counts:
```php
Post::withCount(['comments as approved_count' => fn ($q) => $q->where('approved', true)])->get();
```

`withCount()` adds a correlated subquery to the select list — one query total,
no child models hydrated. `with()` loads every child row to produce an integer.

---

## Q-BLADE-RELATION / Q-BLADE-NESTED-LOOP — relations walked in a template

**Matches:** a relation chain, or a nested `@foreach` over a relation of the
outer row, inside `@foreach` / `@forelse`.

**Confirm:** open the controller that renders the view and look at the query.
This is the one rule that genuinely cannot be confirmed from the matched file
alone — a Blade template has no idea what was eager loaded.

**Fix:** `with()` in the controller, not in the view. Resist adding `load()` in
the template; it hides the cost in the layer least likely to be reviewed.

**Trap:** view composers and `@include` inside a loop can each add their own
queries. Follow the includes.

---

## Q-PHP-AGGREGATE — summing or grouping in PHP

**Matches:** `+=` or `?? 0) +` against a model attribute inside a loop body.

**Confirm:** the aggregate must be expressible in SQL. Anything requiring PHP
logic per row (currency conversion against a live rate, say) is not a finding.

**Fix:**
```php
// Before
$orders = Order::where('status', 'paid')->get();
foreach ($orders as $order) { $totals[$month] += $order->total_cents; }

// After - one query, no hydration
Order::query()
    ->where('status', 'paid')
    ->selectRaw("strftime('%Y-%m', placed_at) as month, SUM(total_cents) as total")
    ->groupBy('month')
    ->pluck('total', 'month');
```
Use the database's own date function — `DATE_FORMAT` on MySQL, `to_char` on
Postgres, `strftime` on SQLite. If the app supports more than one, put the
expression behind a small helper rather than hardcoding one dialect.

---

## Patterns the scanner cannot see

State these as caveats in the report rather than implying coverage:

- **Accessors that query.** An accessor calling `$this->relation` turns an
  attribute read into a query. Grep accessors on any model that appears in a
  finding.
- **Model events and observers.** A `saved` listener that queries turns a bulk
  update into N queries.
- **`$with` on the model.** Removes N+1s globally and adds cost to every query
  that does not need the relation.
- **Polymorphic relations.** `with()` on a `morphTo` loads each type in a
  separate query; `morphWith()` is the fix.
- **Queries behind an interface.** A repository or service method hides the
  query from a pattern scan entirely.
- **`whereHas` without `with`.** Filters correctly, still lazy-loads on access.
  They solve different problems and are often confused for each other.

## Measuring

```php
DB::enableQueryLog();
// ... exercise the code path ...
logger()->info('queries: '.count(DB::getQueryLog()));
```

Telescope, Debugbar and Nightwatch already count this per request — if the
project has one installed, use it rather than instrumenting by hand.
