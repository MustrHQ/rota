# Security policy

## Supported versions

Security fixes are made for the latest release. Please update to it before reporting —
the **Update** page in the admin panel makes that a one-file upload.

| Version | Supported |
|---|---|
| 1.0.x | Yes |
| Older | No — please update |

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report privately using GitHub's
[private vulnerability reporting](https://github.com/MustrHQ/rota/security/advisories/new)
(the **Security → Report a vulnerability** button on this repository), or by email to
team@sparkx.digital.

Please include:

- the version you tested,
- the page or file affected,
- steps to reproduce, and what an attacker could achieve,
- any suggested fix, if you have one.

You will get an acknowledgement within a few working days. Once the issue is confirmed, a fix
is prepared and released, and you are credited in the release notes unless you would rather
not be.

## Scope

In scope: the code in this repository. Out of scope: problems that need an already-compromised
admin account or server, missing HTTPS on an installation, and the configuration of your own
hosting. The README lists the steps every operator should take — HTTPS, nightly database
backups and least-privilege roles.
