---
description: Require automated tests for every PHP change
applyTo: "**/*.php"
---
# Automated Tests Required (PHP)

For **any new or updated PHP implementation**, you MUST add/update **executable automated tests** (even if the user didn't ask).

## Requirements

- **No "tests-only" markdown substitutes**: do NOT create `TESTS.md` (or similar) instead of real tests unless the user explicitly asks for manual test cases.
- **Framework**: prefer PHPUnit (or Pest if the project already uses it). If no test framework exists, add one and a runnable command.
- **Location**: put tests under `tests/` (or the project's established test folder).
- **Coverage**: include automated tests for:
  - happy paths
  - boundary conditions
  - invalid inputs
  - failure scenarios (network/IO/exceptions/timeouts)
  - regressions when relevant
- **External APIs**: mock/stub HTTP where possible; only use live integration tests when explicitly requested (or behind env flags).

## Clarity rules

- **Avoid assumptions**: rely on stated behavior and code evidence.
- **If requirements are unclear**: ask targeted questions (use structured questions when possible) *before* writing tests.