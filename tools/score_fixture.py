#!/usr/bin/env python3
"""
Score a skill's locate pass against the demo-app answer key.

    python3 tools/score_fixture.py

Recall  - every flaw in examples/demo-app/FLAWS.md that the scanner is meant to
          surface produces at least one candidate, with the expected rule.
Precision - the deliberately clean files produce no candidates at all.

Both matter. A scanner that flags everything has perfect recall and is useless.
"""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FIXTURE = ROOT / "examples" / "demo-app"
SCANNER = ROOT / "skills" / "laravel-query-audit" / "scripts" / "scan-queries.sh"

# flaw id -> (file suffix, rule the scanner should raise)
EXPECTED = {
    "Q1":  ("app/Http/Controllers/PostController.php", "Q-ALL"),
    "Q2":  ("resources/views/posts/index.blade.php", "Q-BLADE-RELATION"),
    "Q3":  ("resources/views/posts/index.blade.php", "Q-COUNT-HYDRATE"),
    "Q4":  ("resources/views/posts/index.blade.php", "Q-BLADE-NESTED-LOOP"),
    "Q5":  ("app/Http/Controllers/PostController.php", "Q-LOOP-QUERY"),
    "Q6":  ("app/Http/Controllers/PostController.php", "Q-COUNT-HYDRATE"),
    "Q7":  ("app/Http/Controllers/ReportController.php", "Q-LOOP-RELATION"),
    "Q8":  ("app/Http/Controllers/ReportController.php", "Q-PHP-AGGREGATE"),
    "Q9":  ("database/migrations/2026_01_01_000001_create_posts_table.php", "Q-MISSING-INDEX"),
    "Q10": ("database/migrations/2026_01_01_000002_create_orders_table.php", "Q-MISSING-INDEX"),
}

# Files planted clean on purpose. Any candidate here is a precision failure.
CLEAN = [
    "app/Models/Comment.php",
    "app/Models/Order.php",
    "app/Http/Controllers/Controller.php",
]


def scan() -> list[dict]:
    result = subprocess.run(
        ["bash", str(SCANNER), str(FIXTURE), "--quiet"],
        capture_output=True, text=True,
    )
    if result.returncode != 0:
        print(f"scanner exited {result.returncode}\n{result.stderr}")
        sys.exit(1)

    rows = []
    for line in result.stdout.splitlines():
        parts = line.split("\t")
        if len(parts) >= 6:
            rows.append({"rule": parts[0], "severity": parts[1], "file": parts[2],
                         "line": parts[3], "message": parts[4], "snippet": parts[5]})
    return rows


def main() -> int:
    rows = scan()
    failures = []

    print(f"{len(rows)} candidate(s) from the locate pass\n")

    print("Recall")
    for flaw, (suffix, rule) in EXPECTED.items():
        hit = next((r for r in rows if r["file"].endswith(suffix) and r["rule"] == rule), None)
        if hit:
            print(f"  ok    {flaw:<4} {rule:<21} {suffix.split('/')[-1]}:{hit['line']}")
        else:
            print(f"  MISS  {flaw:<4} {rule:<21} {suffix}")
            failures.append(f"{flaw}: no {rule} candidate in {suffix}")

    print("\nPrecision")
    for clean in CLEAN:
        noise = [r for r in rows if r["file"].endswith(clean)]
        if noise:
            print(f"  NOISE {clean} -> {len(noise)} candidate(s)")
            failures.extend(f"false positive in {clean}: {r['rule']} line {r['line']}" for r in noise)
        else:
            print(f"  ok    {clean} is clean")

    covered = {r["file"] for r in rows}
    expected_files = {s for s, _ in EXPECTED.values()}
    print(f"\n{len(rows)} candidates across {len(covered)} file(s); "
          f"{len(EXPECTED) - len(failures)}/{len(EXPECTED)} planted flaws located")

    if failures:
        print()
        for f in failures:
            print(f"FAIL  {f}")
        return 1

    _ = expected_files
    print("\nRecall and precision both hold against the answer key.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
