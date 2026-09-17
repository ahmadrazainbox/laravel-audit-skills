# tests

Fixtures that prove `tools/validate_skills.py` actually fails when it should.

A validator nobody tests is a green checkmark with no meaning behind it. Each
directory under `fixtures/` is a deliberately broken skill; `tools/test_validator.py`
asserts that the validator reports the expected error for each, and that the
`control` fixture passes cleanly.

```bash
python3 tools/test_validator.py
```

Add a fixture whenever you add a check.
