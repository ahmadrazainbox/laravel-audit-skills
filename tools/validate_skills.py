#!/usr/bin/env python3
"""
Validate every SKILL.md in this repository against the Agent Skills standard.

No third-party dependencies required. If PyYAML is installed it is used for
frontmatter parsing; otherwise a small built-in parser handles the flat
scalar/list frontmatter that skills use.

Usage:
    python3 tools/validate_skills.py [--strict] [path ...]

Exit codes:
    0  all checks passed (warnings allowed unless --strict)
    1  at least one error (or, with --strict, at least one warning)
"""

from __future__ import annotations

import argparse
import os
import re
import sys
from pathlib import Path

# ---------------------------------------------------------------------------
# Spec limits. Sources:
#   https://code.claude.com/docs/en/skills
#   https://agentskills.io
# ---------------------------------------------------------------------------
DESCRIPTION_BUDGET = 1536   # description + when_to_use, combined
COMPATIBILITY_MAX = 500
BODY_LINE_BUDGET = 500      # keep SKILL.md small; push detail into references/
NAME_MAX = 64

KNOWN_KEYS = {
    # Agent Skills standard
    "name", "description", "license", "compatibility", "version", "metadata",
    # Claude Code extensions
    "when_to_use", "argument-hint", "arguments", "allowed-tools",
    "disallowed-tools", "disable-model-invocation", "user-invocable", "model",
    "effort", "context", "agent", "background", "hooks", "paths", "shell",
}

NAME_RE = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
LINK_RE = re.compile(r"\[[^\]]*\]\(([^)]+)\)")

GREEN, RED, YELLOW, DIM, RESET = "\033[32m", "\033[31m", "\033[33m", "\033[2m", "\033[0m"
if not sys.stdout.isatty() or os.environ.get("NO_COLOR"):
    GREEN = RED = YELLOW = DIM = RESET = ""


class Report:
    def __init__(self) -> None:
        self.errors: list[str] = []
        self.warnings: list[str] = []

    def error(self, msg: str) -> None:
        self.errors.append(msg)

    def warn(self, msg: str) -> None:
        self.warnings.append(msg)


def split_frontmatter(text: str, rep: Report) -> tuple[str, str] | None:
    """Return (frontmatter, body) or None if the file has no usable frontmatter."""
    if text.startswith("﻿"):
        rep.error("file starts with a UTF-8 BOM; the opening '---' must be the very first bytes")
        text = text.lstrip("﻿")
    lines = text.splitlines()
    if not lines or lines[0].strip() != "---":
        rep.error("first line must be exactly '---' or the whole file is treated as content, not frontmatter")
        return None
    for i in range(1, len(lines)):
        if lines[i].strip() in ("---", "..."):
            return "\n".join(lines[1:i]), "\n".join(lines[i + 1:])
    rep.error("frontmatter is never closed with '---'")
    return None


def parse_frontmatter(raw: str, rep: Report) -> dict:
    try:
        import yaml  # type: ignore
    except ImportError:
        return _parse_simple(raw, rep)
    try:
        data = yaml.safe_load(raw)
    except Exception as exc:  # noqa: BLE001
        rep.error(f"frontmatter is not valid YAML: {exc}")
        return {}
    if data is None:
        rep.error("frontmatter is empty")
        return {}
    if not isinstance(data, dict):
        rep.error("frontmatter must be a mapping of keys to values")
        return {}
    return data


def _parse_simple(raw: str, rep: Report) -> dict:
    """Dependency-free fallback: flat `key: value` and `key:\n  - item` forms."""
    data: dict = {}
    key = None
    for lineno, line in enumerate(raw.splitlines(), start=2):
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        if "\t" in line:
            rep.error(f"line {lineno}: tab character in frontmatter; YAML forbids tabs for indentation")
            continue
        if line.startswith((" ", "-")):
            stripped = line.strip()
            if stripped.startswith("- ") and key:
                data.setdefault(key, [])
                if isinstance(data[key], list):
                    data[key].append(_scalar(stripped[2:]))
            continue
        if ":" not in line:
            rep.error(f"line {lineno}: expected 'key: value'")
            continue
        key, _, value = line.partition(":")
        key = key.strip()
        value = value.strip()
        data[key] = _scalar(value) if value else None
    return data


def _scalar(value: str):
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
        return value[1:-1]
    if value.lower() in ("true", "yes", "on"):
        return True
    if value.lower() in ("false", "no", "off"):
        return False
    return value


def check_skill(skill_dir: Path) -> Report:
    rep = Report()
    skill_md = skill_dir / "SKILL.md"

    if not skill_md.is_file():
        rep.error("no SKILL.md in this directory")
        return rep

    text = skill_md.read_text(encoding="utf-8")
    parts = split_frontmatter(text, rep)
    if parts is None:
        return rep
    raw_fm, body = parts
    fm = parse_frontmatter(raw_fm, rep)
    if not fm:
        return rep

    # --- name -------------------------------------------------------------
    name = fm.get("name")
    if name is None:
        rep.warn("no 'name'; agents will fall back to the directory name")
    else:
        name = str(name)
        if not NAME_RE.match(name):
            rep.error(f"name '{name}' must be lowercase kebab-case (a-z, 0-9, hyphens)")
        if len(name) > NAME_MAX:
            rep.error(f"name is {len(name)} chars; the limit is {NAME_MAX}")
        if name != skill_dir.name:
            rep.error(f"name '{name}' does not match directory '{skill_dir.name}'; invocation resolves by directory")

    # --- description ------------------------------------------------------
    description = fm.get("description")
    if not description or not str(description).strip():
        rep.error("'description' is required; it is what the agent reads to decide when to load the skill")
    else:
        description = str(description)
        when = str(fm.get("when_to_use") or "")
        total = len(description) + len(when)
        if total > DESCRIPTION_BUDGET:
            rep.error(f"description + when_to_use is {total} chars; the limit is {DESCRIPTION_BUDGET}")
        elif total > DESCRIPTION_BUDGET * 0.9:
            rep.warn(f"description + when_to_use is {total} chars, close to the {DESCRIPTION_BUDGET} limit")
        if len(description) < 40:
            rep.warn("description is very short; agents trigger poorly on vague descriptions")
        if not re.search(r"\b(use (this )?when|when the user|triggers? on|for when)\b", description, re.I) \
           and not when:
            rep.warn("description states what the skill is but not when to use it; add trigger phrasing or 'when_to_use'")

    # --- license / compatibility -----------------------------------------
    if not fm.get("license"):
        rep.warn("no 'license'; the Agent Skills standard recommends declaring one")
    compat = fm.get("compatibility")
    if compat and len(str(compat)) > COMPATIBILITY_MAX:
        rep.error(f"compatibility is {len(str(compat))} chars; the limit is {COMPATIBILITY_MAX}")

    # --- unknown keys -----------------------------------------------------
    for key in fm:
        if key not in KNOWN_KEYS:
            rep.warn(f"unrecognized frontmatter key '{key}'; put custom data under 'metadata'")

    # --- body size --------------------------------------------------------
    body_lines = len(body.splitlines())
    if body_lines > BODY_LINE_BUDGET:
        rep.error(f"SKILL.md body is {body_lines} lines; keep it under {BODY_LINE_BUDGET} and move detail into references/")
    elif body_lines > BODY_LINE_BUDGET * 0.8:
        rep.warn(f"SKILL.md body is {body_lines} lines, approaching the {BODY_LINE_BUDGET} guideline")

    # --- links resolve ----------------------------------------------------
    for target in LINK_RE.findall(body):
        target = target.split("#", 1)[0].strip()
        if not target or target.startswith(("http://", "https://", "mailto:", "#", "$")):
            continue
        if not (skill_dir / target).exists():
            rep.error(f"links to '{target}', which does not exist")

    # --- scripts are executable ------------------------------------------
    scripts_dir = skill_dir / "scripts"
    if scripts_dir.is_dir():
        for script in sorted(scripts_dir.iterdir()):
            if script.suffix in (".sh", ".bash") and not os.access(script, os.X_OK):
                rep.error(f"scripts/{script.name} is not executable (chmod +x)")

    # --- orphaned supporting files ---------------------------------------
    for sub in ("references", "scripts"):
        d = skill_dir / sub
        if d.is_dir():
            for f in sorted(d.iterdir()):
                if f.is_file() and f.name not in text:
                    rep.warn(f"{sub}/{f.name} is never referenced from SKILL.md; an unreferenced file never loads")

    return rep


def discover(paths: list[str], repo_root: Path) -> list[Path]:
    if paths:
        return [Path(p).resolve() for p in paths]
    skills_root = repo_root / "skills"
    if not skills_root.is_dir():
        return []
    return sorted(d for d in skills_root.iterdir() if d.is_dir())


def main() -> int:
    parser = argparse.ArgumentParser(description="Validate SKILL.md files against the Agent Skills standard.")
    parser.add_argument("paths", nargs="*", help="skill directories (default: every directory under skills/)")
    parser.add_argument("--strict", action="store_true", help="treat warnings as failures")
    args = parser.parse_args()

    repo_root = Path(__file__).resolve().parent.parent
    skill_dirs = discover(args.paths, repo_root)

    if not skill_dirs:
        print(f"{RED}No skill directories found under skills/{RESET}")
        return 1

    total_errors = total_warnings = 0
    for skill_dir in skill_dirs:
        rep = check_skill(skill_dir)
        total_errors += len(rep.errors)
        total_warnings += len(rep.warnings)

        if not rep.errors and not rep.warnings:
            print(f"{GREEN}PASS{RESET}  {skill_dir.name}")
            continue
        status = f"{RED}FAIL{RESET}" if rep.errors else f"{YELLOW}WARN{RESET}"
        print(f"{status}  {skill_dir.name}")
        for msg in rep.errors:
            print(f"        {RED}error{RESET}  {msg}")
        for msg in rep.warnings:
            print(f"        {YELLOW}warn {RESET}  {msg}")

    print()
    print(f"{DIM}{len(skill_dirs)} skill(s) checked - {total_errors} error(s), {total_warnings} warning(s){RESET}")

    if total_errors:
        return 1
    if args.strict and total_warnings:
        print(f"{RED}--strict: warnings are failures{RESET}")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
