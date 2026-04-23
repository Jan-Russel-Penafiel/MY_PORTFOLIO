---
name: php-conventions
description: Apply pragmatic, broadly accepted PHP conventions centered on readability and maintainability without being overly prescriptive. Use when writing, refactoring, or reviewing PHP code, or when designing types/APIs.
---

# PHP Conventions (Pragmatic)

## Goal
Write PHP that is **readable and maintainable**, while matching the repo's existing style, PHP version, framework conventions, and tooling.

## Common conventions (non-prescriptive)

Use these as defaults when they fit; follow the repo when they don't.

- **Consistency first**: mirror nearby patterns (naming, file layout, framework conventions, and tooling).
- **Lean on standards when present**: if the repo uses PSR-12/PSR-4 and formatter/linter tooling, follow it; otherwise, follow local style.
- **Clarity at boundaries**: be explicit where data crosses a boundary (public APIs, I/O, framework edges).
- **Types that communicate**: prefer parameter/return types and typed properties where they improve clarity; avoid cleverness.
- **Make nullability obvious**: use `?Type` and sensible defaults when that improves readability.
- **Testable design**: keep core logic deterministic; isolate side effects; inject dependencies rather than hard-coding globals.

## Types & PHPDoc (quick notes)

- Use native types first; use PHPDoc to clarify shapes where native types can't express them.
- Keep return shapes consistent and predictable.
