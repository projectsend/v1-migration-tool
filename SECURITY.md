# Security Policy

This package is part of ProjectSend and shares its security policy.

**Report a vulnerability** through
[GitHub's private vulnerability reporting](https://github.com/projectsend/v1-migration-tool/security/advisories/new)
on this repository, or email <contact@projectsend.org>. Please do not open a public issue.

The full policy — what helps in a report, what to expect, what is in scope — is at
[projectsend/projectsend `SECURITY.md`](https://github.com/projectsend/projectsend/blob/main/SECURITY.md).

Worth naming for this package in particular: it reads a v1 installation's database credentials out
of that installation's own configuration, connects to it, and writes across the whole of a v2
schema. It never writes to the v1 install. If you find it doing either of those things differently
than described, that is a report worth making.
