# Answer key

Every flaw planted in this fixture, with the file it lives in and the skill
that should find it. Tests and evals assert against this list, so keep the IDs
stable and add new rows at the end rather than renumbering.

`skills/*/scripts/*.sh` should surface a candidate for each row. The skill
itself then has to confirm it and propose the fix — a scanner that flags the
line but cannot explain it has not done the job.

## laravel-query-audit

| ID | File | Line of attack | Severity |
|----|------|----------------|----------|
| Q1 | `app/Http/Controllers/PostController.php` | `Post::all()` in `index()` with no eager load, feeding a view that walks three relations | high |
| Q2 | `resources/views/posts/index.blade.php` | `$post->author->name` inside `@foreach` | high |
| Q3 | `resources/views/posts/index.blade.php` | `$post->comments->count()` hydrates every comment to produce an integer | high |
| Q4 | `resources/views/posts/index.blade.php` | `@foreach ($post->tags as $tag)` — third relation walked per row | medium |
| Q5 | `app/Http/Controllers/PostController.php` | `DB::table('users')->where(...)->first()` inside `foreach` in `show()` | high |
| Q6 | `app/Http/Controllers/PostController.php` | `popular()` sorts by `$post->comments->count()` in PHP instead of `withCount()` | medium |
| Q7 | `app/Http/Controllers/ReportController.php` | `$order->customer->name` inside the export loop | high |
| Q8 | `app/Http/Controllers/ReportController.php` | `monthlyTotals()` aggregates in PHP what SQL can `GROUP BY` | medium |
| Q9 | `database/migrations/2026_01_01_000001_create_posts_table.php` | `user_id` foreign key with no index | medium |
| Q10 | `database/migrations/2026_01_01_000002_create_orders_table.php` | `status` and `placed_at` filtered and sorted without indexes | medium |

## laravel-security-audit

| ID | File | Line of attack | OWASP | Severity |
|----|------|----------------|-------|----------|
| S1 | `app/Models/Post.php` | `protected $guarded = []` disables mass-assignment protection | A04 | critical |
| S2 | `app/Http/Controllers/PostController.php` | `Post::create($request->all())` | A04 | critical |
| S3 | `app/Http/Controllers/PostController.php` | `whereRaw("title LIKE '%{$term}%'")` — user input in raw SQL | A03 | critical |
| S4 | `app/Http/Controllers/PostController.php` | `orderByRaw($request->input('sort', ...))` — user controls the ORDER BY clause | A03 | critical |
| S5 | `app/Http/Controllers/Admin/UserController.php` | `update()` and `destroy()` with no `authorize()`, policy or gate | A01 | critical |
| S6 | `app/Models/User.php` | empty `$hidden`, so `password` and `api_token` serialise into JSON | A02 | critical |
| S7 | `app/Http/Controllers/Admin/UserController.php` | returns User models directly as an API response | A02 | high |
| S8 | `app/Http/Controllers/AvatarController.php` | client-supplied filename written to disk | A03 | high |
| S9 | `app/Http/Controllers/AvatarController.php` | no MIME or extension validation on upload | A08 | high |
| S10 | `app/Http/Controllers/AvatarController.php` | user uploads written to a publicly served disk | A01 | medium |
| S11 | `app/Services/PaymentGateway.php` | `env()` called outside `config/` — returns null once config is cached | A05 | high |
| S12 | `app/Services/PaymentGateway.php` | logs the full gateway response, including the card fingerprint | A09 | high |
| S13 | `config/app.php` | `'debug' => env('APP_DEBUG', true)` — defaults to on | A05 | high |
| S14 | `routes/web.php` | `/login` has no throttle middleware | A07 | high |
| S15 | `routes/web.php` | admin group is behind `auth` but no admin gate | A01 | high |

## laravel-bulk-data

| ID | File | Line of attack | Severity |
|----|------|----------------|----------|
| B1 | `app/Http/Controllers/ReportController.php` | `Order::all()` hydrates the whole table before writing a byte | critical |
| B2 | `app/Http/Controllers/ReportController.php` | export holds every model in memory for the duration of the stream | critical |
| B3 | `app/Http/Controllers/ReportController.php` | `monthlyTotals()` loads every paid order to produce twelve numbers | high |

## Deliberately clean

These exist so a scanner that flags everything fails the fixture:

- `app/Models/Comment.php` — explicit `$fillable`, no raw SQL.
- `app/Models/Order.php` — explicit `$fillable`.
- `app/Http/Controllers/Controller.php` — plain base controller.
