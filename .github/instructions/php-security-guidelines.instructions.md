---
description: Secure credential handling for PHP projects
applyTo: "**/*.php"
---
# Credential Storage Rules
## Core Principles
- **Never hardcode credentials** such as API keys, secrets, passwords, tokens, or private URLs in source code.
- Secrets must never be committed to version control or exposed in logs, stack traces, or error output.
- Source code must be treated as public and inspectable.
- Encoding, hashing, or obfuscation does not make credentials safe.
## Approved Storage Mechanisms
- Use environment variables as the primary mechanism for credential access:
  - `$_ENV['VAR_NAME']`
  - `getenv('VAR_NAME')`
- Local development may use `.env` files loaded by tooling or frameworks, which must be excluded from version control.
- Production environments must use platform-, container-, or cloud-managed secrets.
## Repository Hygiene
- `.env` and similar files must always be ignored by git.
- If a real `.env` file cannot be created or committed:
  - Create a `.env.example` file.
  - Include variable names only, with empty values.
  - Never include real credentials.