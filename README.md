# laravel-audit-skills

**Agent skills that audit an existing Laravel codebase** — N+1 queries, security
holes and memory blowups — and hand back a findings report with fixes.

Most Laravel agent skills teach an AI *how to write* Laravel. These do a
different job: they walk code that already exists and tell you what is wrong
with it, where, and what it costs.

Works with Claude Code, Codex CLI, Cursor, Gemini CLI and anything else that
reads the [Agent Skills](https://agentskills.io) standard.

```bash
git clone https://github.com/ahmadraza/laravel-audit-skills
cd laravel-audit-skills && ./install.sh
```

Then, in your Laravel project:

> audit this codebase for N+1 queries

---

## What it finds, and what that costs

The repository ships a fixture — [`examples/demo-app`](examples/demo-app) — with
28 deliberately planted flaws and an [answer key](examples/demo-app/FLAWS.md).
The [benchmarks](examples/benchmarks) put a number on them. Reproduce both with
nothing but PHP:

```bash
php examples/benchmarks/prove-n-plus-one.php --rows 500
```

```
Post::all() + relations walked in the view
  queries                            1,001
  time                               84.1 ms

with('author')->withCount('comments')
  queries                            3
  time                               2.0 ms
```

**1,001 queries against 3.** And that is the friendly case — SQLite in memory,
no network. Over a socket to MySQL or Postgres each of those 1,001 queries also
pays a round trip.

```bash
php examples/benchmarks/prove-memory-blowup.php --rows 25000
```

| rows | `Order::all()` | `->cursor()` |
|---|---|---|
| 25,000 | 11.4 MB | no measurable growth |
| 50,000 | 22.8 MB | no measurable growth |

One column tracks the table size. The other does not move.

---

## The skills

### `laravel-query-audit`

Finds N+1 queries and Eloquent performance problems. Locates candidates, then
confirms each one against the code that feeds it — a relation walked in a Blade
template is only an N+1 if the controller did not eager load it. Reports the
query count as a formula (`1 + 2N`), which is what makes a finding persuasive,
and gives every fix as a diff.

Also covers: counting by hydrating, aggregation in PHP that belongs in SQL,
queries inside loops, and foreign keys with no index.

Its locate pass ships as a script — [`scan-queries.sh`](skills/laravel-query-audit/scripts/scan-queries.sh),
nine rules, no dependencies. See [what a real report looks like](examples/reports/query-audit.md).

> *"this page takes four seconds to load"*

### `laravel-security-audit`

Reviews the eight vulnerability classes that actually appear in Laravel apps,
each mapped to an OWASP Top 10 category: mass assignment, raw SQL injection,
missing authorization, sensitive data exposure, insecure uploads, config and
secret handling, authentication hardening, logging and dependencies.

Severity is assigned by exploitability, not by pattern — `whereRaw` with a
hardcoded string is not a finding; the same call carrying `$request->input()`
is critical. Every finding states, in one concrete sentence, what an attacker
does with it.

> *"security review before we ship this"*

### `laravel-bulk-data`

Finds code that loads whole tables into memory and converts it to the right
streaming primitive — including the decision table for `chunkById` vs `cursor`
vs `lazy` vs `lazyById` vs queued batches, and the two rules that matter more
than the table: never `chunk()` while modifying the chunked column, and
`cursor()` cannot cross a queue boundary.

> *"the CSV export runs out of memory"*

---

## Install

```bash
./install.sh                       # Claude Code, personal (~/.claude/skills)
./install.sh --target project      # this repo only (.claude/skills) - commit to share
./install.sh --agent codex         # Codex CLI
./install.sh --agent cursor        # Cursor
./install.sh --agent gemini        # Gemini CLI
./install.sh --agent opencode      # OpenCode

./install.sh --skill laravel-query-audit   # just one
./install.sh --dry-run                     # show what would happen
./install.sh --uninstall
```

Or copy a single skill folder into `~/.claude/skills/` yourself — they are
plain directories with a `SKILL.md`, nothing to build.

---

## How they work

Each skill runs three passes, and the split is deliberate:

1. **Locate** — pattern search across the codebase. Cheap, high recall, high
   false-positive rate. The point is to spend reading budget only where there
   might be something.
2. **Confirm** — read the surrounding code and decide. Most candidates die
   here. A scanner that reports every `whereRaw` is noise; the judgement is the
   product.
3. **Report** — severity, file, line, one concrete sentence of impact, and the
   fix as a diff against the real lines.

Deterministic work belongs in `scripts/`; judgement belongs in the markdown.
That split is what separates a skill from a long prompt.

Each skill also states what it *cannot* see. A source review does not cover
infrastructure, runtime configuration or anything behind a queue worker, and an
audit that implies otherwise is worse than no audit.

---

## Contributing

New checks are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). The short
version: add the flawed code to `examples/demo-app`, add a row to
[`FLAWS.md`](examples/demo-app/FLAWS.md), then teach the skill to find it. The
fixture is the test suite, and it contains deliberately clean files so a check
that flags everything fails.

```bash
python3 tools/validate_skills.py --strict
```

---

## Licence

MIT. Built by [Ahmad Raza](https://github.com/ahmadraza) — senior full-stack
developer, six years of Laravel and PHP for UK and US teams. These checks are
the code review I have been doing by hand, written down so an agent can run it.
