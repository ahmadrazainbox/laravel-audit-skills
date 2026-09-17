---
name: laravel-security-audit
description: Reviews an existing Laravel codebase for the vulnerability classes that actually appear in Laravel apps - mass assignment, raw SQL injection, missing authorization, insecure uploads, leaked config and over-exposed API responses - and reports each with file, line, OWASP category and fix. Use when the user asks for a security review or audit, asks whether code is safe, asks about vulnerabilities, or is preparing a release or handover.
license: MIT
compatibility: Requires read access to a Laravel codebase (Laravel 8 or newer). Uses Grep, Glob and Read. Optionally runs composer audit and php artisan route:list, both read-only. Never modifies code unless the user asks.
metadata:
  repository: https://github.com/ahmadraza/laravel-audit-skills
  fixture: examples/demo-app
---

# Laravel security audit

Review a Laravel codebase for the vulnerability classes that show up in real
Laravel applications, and report each one with the line, the impact and the fix.

This is a source review. It finds patterns that are wrong by construction. It
does not replace a penetration test, and it cannot see runtime or
infrastructure issues. Say so in the report rather than implying coverage you
do not have.

## Scope

Work through these eight classes in order. Each maps to an OWASP Top 10
category, which is what makes the report legible to anyone who has to sign off
on it.

### 1. Mass assignment (A04)

Search for `$guarded = []`, models with neither `$fillable` nor `$guarded`, and
`$request->all()` passed to `create()`, `update()`, `fill()` or `forceFill()`.

An empty `$guarded` plus `$request->all()` is the canonical Laravel
privilege-escalation bug: the attacker adds `is_admin=1` to the form post.

Fix: an explicit `$fillable`, and `$request->validated()` from a FormRequest
rather than `all()`.

### 2. SQL injection (A03)

Search for `whereRaw`, `orderByRaw`, `havingRaw`, `selectRaw`, `DB::raw`,
`DB::statement`, `DB::select`. For each, decide whether any part of the string
is interpolated (`{$var}`, `.$var.`, `sprintf`) rather than bound.

`orderByRaw` with user input deserves special attention: bindings do not protect
a column name, so the fix is an allowlist, not a placeholder.

Fix: bindings for values (`whereRaw('x = ?', [$v])`); an allowlist match for
column and direction names.

### 3. Broken access control (A01)

For every controller action that reads, writes or deletes a record belonging to
a user, check for an `authorize()` call, a `can` middleware, a policy, a gate,
or route-model binding scoped to the owner.

`middleware('auth')` alone is not authorization — it proves someone is logged
in, not that they may touch *this* row. An admin route group behind `auth` with
no admin gate is a finding, and usually a critical one.

Fix: a policy plus `$this->authorize('update', $model)`, or `can:` middleware
on the route.

### 4. Sensitive data exposure (A02)

Check `$hidden` on every model that holds a password, token, secret or
personally identifying field. Check whether controllers return models or
collections directly as JSON instead of an API Resource.

Returning `User::paginate()` from a controller serialises whatever `$hidden`
does not cover — including `password` and `remember_token`.

Fix: `$hidden` on the model *and* an explicit API Resource. Rely on both; the
Resource is what makes the contract visible.

### 5. Insecure file upload (A08, A03)

Look at every `store`, `storeAs`, `put`, `putFile` and `move` on an uploaded
file. Check three things: is the filename derived from client input; is the MIME
type or extension validated; is the destination disk publicly served.

Client-supplied filenames allow path traversal and overwriting; an unvalidated
upload to a public disk allows serving attacker-controlled content from your
own origin.

Fix: `$file->store('dir')` (Laravel generates the name), a `mimes:` or
`mimetypes:` validation rule, and a private disk with a signed-URL accessor.

### 6. Configuration and secrets (A05)

Search for `env(` outside `config/`. Check `APP_DEBUG`'s default in
`config/app.php`. Check whether `.env` is in `.gitignore` and whether it was
ever committed (`git log --all -- .env`).

`env()` outside config is not just style: once `php artisan config:cache` runs
in production, those calls return `null`, silently. `APP_DEBUG` defaulting to
`true` means an unset variable exposes a stack trace with every environment
value in it.

Fix: read config values through `config()`; default `APP_DEBUG` to `false`.

### 7. Authentication hardening (A07)

Check login, registration, password-reset and OTP routes for `throttle`
middleware. Check session config for `secure`, `http_only` and `same_site`.
Check for signed URLs on any route that acts without a session.

Fix: `throttle:6,1` on auth endpoints, secure session cookies, `signed`
middleware where relevant.

### 8. Logging and dependencies (A09, A06)

Search for `logger()`, `Log::`, `info(`, `dd(`, `dump(`, `ray(` calls that pass
a request, a response, or a whole model — those write tokens and card data into
logs that are shipped off-box. Flag any `dd`/`dump` left in non-test code.

Run `composer audit` if the project has a lockfile. Report advisories with
severity and the version that fixes them.

## Reporting

Group findings by severity, not by file. A reader triaging a report wants the
critical ones together.

```
| # | Severity | OWASP | File | Issue |
|---|----------|-------|------|-------|
| 1 | critical | A04   | app/Models/Post.php:13 | $guarded = [] plus create($request->all()) |
```

Then, per finding: what an attacker does with it, in one concrete sentence, and
the fix as a diff.

"An attacker posts `is_admin=1` to `POST /posts` and becomes an administrator"
lands. "Mass assignment vulnerability detected" does not.

Rules:

- **Severity by exploitability, not by pattern.** `whereRaw` with a hardcoded
  string is not a finding. The same call with `$request->input()` in it is
  critical. Read the data flow before assigning severity.
- **Never invent a CVE or a severity score.** Report what `composer audit`
  says, attributed to it.
- **Say what you could not check.** Infrastructure, runtime config, secrets in
  the deployment environment and anything behind a queue worker are outside a
  source review. A short "not covered" section is part of an honest audit.
- **No fix without a diff.** If the fix is architectural and does not fit in a
  diff, say that explicitly and describe the shape of it.

## Traps

- `$fillable` protects the *model*, not the endpoint. A FormRequest is still
  required.
- Bindings do not parameterise identifiers. `orderByRaw('? ?', [$col, $dir])`
  is not a fix.
- `authorize()` in a controller the route never reaches is dead code; check the
  route actually maps there.
- `$hidden` does not apply to `toArray()` on a query builder result, only on a
  model.
- Validation rules on a nested array (`items.*.price`) are frequently missing
  even when the top-level rule exists.
- `Storage::disk('public')` is served by a symlink from `public/storage`. It is
  world-readable by design.

A fixture containing one instance of each class above, with an answer key, is
in `examples/demo-app` in this skill's repository.
