# Contributing

Thanks for considering it. The bar here is specific: a check earns its place by
finding a real problem in real code without drowning the report in noise.

## Adding a check

Four steps, in this order. The fixture comes first — it is how we know the
check works.

1. **Plant the flaw.** Add the flawed code to `examples/demo-app`, written the
   way it would appear in a real codebase. Not a minimal repro — a plausible
   controller, model or view. Mark it with a `// FLAW:` comment saying what is
   wrong.

2. **Add it to the answer key.** A new row in
   [`examples/demo-app/FLAWS.md`](examples/demo-app/FLAWS.md) with a fresh
   stable ID (append, never renumber), the file, the line of attack, severity,
   and for security checks the OWASP category.

3. **Teach the skill.** Add the pattern to the skill's locate pass and, where
   it is not obvious, the reasoning for the confirm pass. If the check needs
   more than a paragraph of background, put that in the skill's `references/`
   directory and link to it from `SKILL.md` — do not grow `SKILL.md` past 500
   lines.

4. **Check precision.** Run the skill against the whole fixture, not just your
   new case. Three files in `demo-app` are deliberately clean. If your check
   fires on them, it is not ready.

## What gets rejected

- Style opinions. This repository audits for correctness, security and cost.
  Whether you prefer `$model->save()` or `Model::create()` is not a finding.
- Checks that cannot state impact. If you cannot write the sentence "an
  attacker does X" or "this costs N queries", the check is not specific enough.
- Findings without a fix. Every check must be able to produce a diff.
- Anything that assumes a specific Laravel version without saying so. Note the
  version range in the check itself.

## Running the checks locally

```bash
python3 tools/validate_skills.py --strict     # SKILL.md conformance
php examples/benchmarks/prove-n-plus-one.php --rows 500
find examples skills -name '*.php' -print0 | xargs -0 -n1 php -l
shellcheck install.sh skills/*/scripts/*.sh
```

CI runs all of the above on every pull request.

## Skill file conventions

- `SKILL.md` frontmatter opens on line 1 with `---`, no BOM, no leading blank.
- `description` says *when to use the skill*, not only what it is — that string
  is what an agent reads to decide whether to load it. Budget: `description` +
  `when_to_use` must stay under 1,536 characters.
- `name` matches the directory name exactly, in lowercase kebab-case.
- Keep `SKILL.md` under 500 lines. Supporting detail goes in `references/`, and
  only loads when the agent follows the link.
- Anything deterministic — a grep pass, a parse, a count — belongs in
  `scripts/`, not in prose the agent has to re-derive each run.
- Every file in `references/` and `scripts/` must be linked from `SKILL.md`. An
  unreferenced file never loads, and the validator warns about it.

## Commit and PR

Small, focused pull requests. One check, or one skill, per PR. Describe the
problem the check finds and include the fixture output showing it found it.
