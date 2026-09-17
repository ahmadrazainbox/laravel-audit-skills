#!/usr/bin/env python3
"""
Assert that validate_skills.py fails on each deliberately broken fixture.

    python3 tools/test_validator.py

Exit code 0 if every expectation holds.
"""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FIXTURES = ROOT / "tests" / "fixtures"
VALIDATOR = ROOT / "tools" / "validate_skills.py"

# fixture -> substring that must appear in the validator's output
EXPECTED = {
    "no-frontmatter": "first line must be exactly '---'",
    "name-mismatch": "does not match directory",
    "too-long": "the limit is 1536",
    "broken-links": "which does not exist",
    "too-many-lines": "keep it under 500",
    "bom": "UTF-8 BOM",
}

# fixture -> substring that must appear, without the run being an error
EXPECTED_WARNINGS = {
    "name-mismatch": "unrecognized frontmatter key",
    "broken-links": "is never referenced from SKILL.md",
}


def run(path: Path) -> tuple[int, str]:
    result = subprocess.run(
        [sys.executable, str(VALIDATOR), str(path)],
        capture_output=True, text=True, env={"NO_COLOR": "1", "PATH": "/usr/bin:/bin"},
    )
    return result.returncode, result.stdout + result.stderr


def main() -> int:
    if not FIXTURES.is_dir():
        print(f"missing fixtures directory: {FIXTURES}")
        return 1

    failures = []

    for name, needle in EXPECTED.items():
        code, output = run(FIXTURES / name)
        if code == 0:
            failures.append(f"{name}: validator exited 0 but should have failed")
        elif needle not in output:
            failures.append(f"{name}: expected {needle!r} in output, got:\n{output}")
        else:
            print(f"ok    {name} rejected ({needle})")

    for name, needle in EXPECTED_WARNINGS.items():
        _, output = run(FIXTURES / name)
        if needle not in output:
            failures.append(f"{name}: expected warning {needle!r} in output")
        else:
            print(f"ok    {name} warns ({needle})")

    code, output = run(FIXTURES / "control")
    if code != 0:
        failures.append(f"control: a valid skill was rejected:\n{output}")
    else:
        print("ok    control accepted")

    # The real skills must also pass, strictly.
    result = subprocess.run(
        [sys.executable, str(VALIDATOR), "--strict"],
        capture_output=True, text=True, cwd=ROOT, env={"NO_COLOR": "1", "PATH": "/usr/bin:/bin"},
    )
    if result.returncode != 0:
        failures.append(f"shipped skills failed --strict:\n{result.stdout}{result.stderr}")
    else:
        print("ok    shipped skills pass --strict")

    print()
    if failures:
        for f in failures:
            print(f"FAIL  {f}")
        return 1
    print("All validator expectations hold.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
