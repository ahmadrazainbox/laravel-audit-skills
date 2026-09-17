# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Repository scaffold: validator, CI, cross-agent installer.
- `examples/demo-app` — Laravel application seeded with deliberate flaws.
- `examples/benchmarks` — zero-dependency PHP harness proving the cost of each flaw.
- Skill skeletons for `laravel-query-audit`, `laravel-security-audit`, `laravel-bulk-data`.
- `laravel-query-audit` locate pass: `scripts/scan-queries.sh` with nine rules,
  statement joining, loop-depth tracking and string-literal stripping.
- `laravel-query-audit` references: `n-plus-one-patterns.md`, `indexing-rules.md`.
- `tools/score_fixture.py` — scores the locate pass against the answer key for
  both recall and precision; wired into CI.
- `examples/reports/query-audit.md` — the skill's real output on the fixture.

### Fixed
- `tools/validate_skills.py` crashed when given a path outside the repository.
- `install.sh` used `A && B || C` where if-then-else was meant (SC2015).
