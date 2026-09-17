# demo-app

A Laravel application slice carrying deliberate, documented flaws. The skills
in this repository are developed and tested against it, and the reports in
[`../reports/`](../reports/) are their real output on this code.

It is a fixture, not a product. There is no `vendor/`, no `artisan` and no
bootstrap — it is the `app/`, `routes/`, `resources/` and `database/` files a
scanner walks, written the way they would appear in a real codebase. If you
want to see the flaws *cost* something rather than just read about them, run
the benchmarks in [`../benchmarks/`](../benchmarks/), which need nothing but
PHP and `pdo_sqlite`.

Every planted flaw is catalogued in [`FLAWS.md`](FLAWS.md) with a stable ID.
That file is the answer key: it is what a skill's output is scored against.

```
demo-app/
├── FLAWS.md                      answer key - 28 planted flaws
├── app/
│   ├── Models/                   Post, User, Comment, Order, Tag
│   ├── Http/Controllers/         posts, reports, avatar upload, admin users
│   └── Services/                 payment gateway
├── config/app.php
├── database/migrations/
├── resources/views/posts/
└── routes/web.php
```

Three of the files are deliberately clean. A scanner that reports findings in
those has a precision problem, and the fixture is designed to catch it.
