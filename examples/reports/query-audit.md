# Query audit — demo-app

Produced by the `laravel-query-audit` skill against
[`examples/demo-app`](../demo-app). This is the skill's real output, kept in the
repository so the format is visible before you install anything.

**Scope:** `app/`, `routes/`, `resources/views/`, `database/migrations/`.
**Locate pass:** 14 candidates. **Confirmed:** 6 findings. **Dismissed:** 8
(folded into the finding that explains them — see *Candidates and their
disposition*).

---

## Findings

| # | Severity | File | Problem |
|---|----------|------|---------|
| 1 | high | `app/Http/Controllers/PostController.php:13` | Posts index issues 1 + 3N queries |
| 2 | high | `app/Http/Controllers/ReportController.php:12` | Order export loads the whole table, then queries per row |
| 3 | high | `app/Http/Controllers/PostController.php:28` | Comment authors fetched one at a time |
| 4 | medium | `app/Http/Controllers/PostController.php:55` | `popular()` hydrates every comment in the database |
| 5 | medium | `app/Http/Controllers/ReportController.php:42` | Monthly totals aggregated in PHP |
| 6 | medium | `database/migrations/*` | Four unindexed columns that are filtered, sorted or joined on |

---

### 1. Posts index issues 1 + 3N queries

`PostController.php:13` loads every post with `Post::all()`, and
`resources/views/posts/index.blade.php` then reads `$post->author` (line 9),
`$post->comments` (line 12) and `$post->tags` (line 16) for each row.

One query for the posts, three per post. At 500 posts that is **1,501
queries**. `Post::all()` also cannot be eager loaded — there is no builder to
attach `with()` to — so the fix has to change the query, not the view alone.

```diff
-        $posts = Post::all();
+        $posts = Post::query()
+            ->with(['author', 'tags'])
+            ->withCount('comments')
+            ->paginate(25);
```

```diff
-            <p>{{ $post->comments->count() }} comments</p>
+            <p>{{ $post->comments_count }} comments</p>
```

`withCount` rather than `with` for the comments: only the number is rendered,
and `with` would hydrate every comment row to produce it.

**Behaviour change:** `paginate()` returns a paginator, not a collection. The
view needs `{{ $posts->links() }}` and any caller expecting the full set must be
checked. If the page genuinely must render every post, drop the `paginate()` and
keep the eager loads — the query count still falls from 1,501 to 4.

---

### 2. Order export loads the whole table, then queries per row

`ReportController.php:12` calls `Order::all()` before writing a single byte,
then `line 21` reads `$order->customer->name` inside the write loop.

Two compounding problems: every order is held in memory for the life of the
response, and each one triggers a customer lookup. At 500k orders that is
**500,001 queries** and, measured on this repository's benchmark, roughly
**455 MB** of rows — before Eloquent hydrates each into an `Order` object.

```diff
-        $orders = Order::all();
-
-        return response()->streamDownload(function () use ($orders) {
+        return response()->streamDownload(function () {
             $out = fopen('php://output', 'w');
             fputcsv($out, ['id', 'customer', 'total', 'placed_at']);
 
-            foreach ($orders as $order) {
-                fputcsv($out, [
-                    $order->id,
-                    $order->customer->name,
-                    $order->total_cents / 100,
-                    $order->placed_at,
-                ]);
-            }
+            Order::query()
+                ->with('customer')
+                ->cursor()
+                ->each(function (Order $order) use ($out) {
+                    fputcsv($out, [
+                        $order->id,
+                        $order->customer->name,
+                        $order->total_cents / 100,
+                        $order->placed_at,
+                    ]);
+                });
 
             fclose($out);
         }, 'orders.csv');
```

`cursor()` fixes the memory; `with('customer')` fixes the N+1. They are
independent problems and both have to be addressed — switching to `cursor()`
alone leaves the 500,000 customer queries in place.

**Note on `with()` + `cursor()`:** eager loading applies within each internal
buffer, not across the whole result set. For a job this size, `chunkById(1000)`
with `with('customer')` inside each chunk is the more predictable shape, and
survives being moved onto a queue. See the `laravel-bulk-data` skill.

---

### 3. Comment authors fetched one at a time

`PostController.php:28` runs `DB::table('users')->where('id', ...)->first()`
inside a `foreach` over `$post->comments`. That is one round trip per comment,
and on a remote database the network cost dominates.

```diff
-        $authors = [];
-        foreach ($post->comments as $comment) {
-            $authors[] = DB::table('users')->where('id', $comment->user_id)->first();
-        }
+        $post->load('comments.author');
+        $authors = $post->comments->pluck('author');
```

If the raw-query shape must be kept, one `whereIn` before the loop achieves the
same thing:

```php
$authors = DB::table('users')
    ->whereIn('id', $post->comments->pluck('user_id'))
    ->get()
    ->keyBy('id');
```

---

### 4. `popular()` hydrates every comment in the database

`PostController.php:55` runs `Post::with('comments')->get()` — unbounded, and
loading every comment row — then sorts in PHP by `$post->comments->count()` at
line 59 to return ten records.

```diff
-        $posts = Post::with('comments')->get();
-
-        return $posts->sortByDesc(fn (Post $post) => $post->comments->count())->take(10);
+        return Post::query()
+            ->withCount('comments')
+            ->orderByDesc('comments_count')
+            ->limit(10)
+            ->get();
```

Two queries become one, the sort moves to the database, and no comment rows are
hydrated at all.

---

### 5. Monthly totals aggregated in PHP

`ReportController.php:42` loads every paid order and accumulates twelve numbers
in a `foreach`.

```diff
-        $orders = Order::where('status', 'paid')->orderBy('placed_at')->get();
-
-        $totals = [];
-        foreach ($orders as $order) {
-            $month = substr((string) $order->placed_at, 0, 7);
-            $totals[$month] = ($totals[$month] ?? 0) + $order->total_cents;
-        }
-
-        return $totals;
+        return Order::query()
+            ->where('status', 'paid')
+            ->selectRaw("DATE_FORMAT(placed_at, '%Y-%m') as month, SUM(total_cents) as total")
+            ->groupBy('month')
+            ->orderBy('month')
+            ->pluck('total', 'month');
```

**Portability:** `DATE_FORMAT` is MySQL. Postgres wants
`to_char(placed_at, 'YYYY-MM')`, SQLite `strftime('%Y-%m', placed_at)`. If this
application targets more than one engine, put the expression behind a helper
rather than hardcoding a dialect in the controller.

---

### 6. Four unindexed columns

| Column | Migration | Why it matters |
|---|---|---|
| `posts.user_id` | `..._create_posts_table.php:15` | Foreign key. Every `with('author')` and every join scans. |
| `orders.user_id` | `..._create_orders_table.php:13` | Same, and the export joins on it per row. |
| `orders.status` | `..._create_orders_table.php:17` | Filtered in `monthlyTotals()`. |
| `orders.placed_at` | `..._create_orders_table.php:18` | Sorted in `monthlyTotals()`. |

`unsignedBigInteger('user_id')` creates a column and nothing else. Laravel does
not index foreign keys implicitly — and `->constrained()` only gets you an index
on MySQL, not on Postgres.

Because `status` and `placed_at` are used together in one query, they want a
composite index in that order, not two separate ones:

```php
Schema::table('posts', function (Blueprint $table) {
    $table->index('user_id');
});

Schema::table('orders', function (Blueprint $table) {
    $table->index('user_id');
    $table->index(['status', 'placed_at']);
});
```

**Before applying this to a live table:** adding an index locks the table on
older MySQL versions and blocks writes for the duration. On Postgres, use
`CREATE INDEX CONCURRENTLY` in a migration with `public $withinTransaction =
false`. Confirm the row counts and run `EXPLAIN` on the two queries first — an
index nobody's planner chooses is pure write cost.

---

## Candidates and their disposition

The locate pass produced 14 candidates. Eight are not separate findings; they
are the *symptoms* of a finding reported above, and folding them in is
deliberate — a report that lists the same problem four times is harder to act
on, not more thorough.

| Candidate | Disposition |
|---|---|
| `index.blade.php:9` `Q-BLADE-RELATION` | symptom of finding 1 |
| `index.blade.php:12` `Q-COUNT-HYDRATE` | symptom of finding 1 |
| `index.blade.php:16` `Q-BLADE-NESTED-LOOP` | symptom of finding 1 |
| `PostController.php:59` `Q-COUNT-HYDRATE` | symptom of finding 4 |
| `ReportController.php:21` `Q-LOOP-RELATION` | symptom of finding 2 |
| `..._orders_table.php:13,17,18` `Q-MISSING-INDEX` | grouped into finding 6 |

Nothing was flagged in `app/Models/Comment.php`, `app/Models/Order.php` or
`app/Http/Controllers/Controller.php`. Those three files are clean by design in
this fixture, and a scanner that reported findings in them would have a
precision problem rather than a thorough one.

---

## Checked and clean

- `app/Models/` — no accessors that query, no `$with` on any model, no
  relations defined with unbounded default scopes.
- `routes/web.php` — no queries in route closures.
- No polymorphic relations, so no `morphWith()` concerns.
- No queue jobs or console commands in this fixture.

## Not covered by this audit

A source review sees patterns, not behaviour. It cannot tell you:

- actual row counts, which decide whether any of the above matters in practice;
- what the query planner does — every index recommendation above needs an
  `EXPLAIN` against real data before it ships;
- queries built inside repositories, services or packages that this scan did not
  walk;
- anything triggered by model events, observers or queue workers at runtime.

To close the loop, wrap the two worst routes in a query log and compare before
and after:

```php
DB::enableQueryLog();
// ... hit /posts and /reports/orders.csv ...
logger()->info('queries: '.count(DB::getQueryLog()));
```
