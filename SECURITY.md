# Security Policy

## Supported versions

`reyemtech/laravel-hubspot` targets PHP `^8.3` and Laravel `11.x`, `12.x`, `13.x`. Security fixes
are backported to every currently supported combination in that matrix — see STANDARDS.md §1 for
the exact support table. A version is considered unsupported once it falls outside that matrix; no
security fixes are backported to unsupported PHP or Laravel versions.

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Email **security@reyem.tech** with a description of the vulnerability, the affected version(s),
and, if possible, a reproduction. You can expect an acknowledgement within 48 hours.

If the report is confirmed as a genuine vulnerability, we aim to ship a patch release within
**48 hours** of confirmation. We will credit the reporter in the release notes unless anonymity is
requested.

## Scope

This policy covers the `reyemtech/laravel-hubspot` package itself. Vulnerabilities in its
dependencies (`hubspot/api-client`, Illuminate components) should be reported to those projects
directly; if a dependency vulnerability affects this package's default configuration, please still
let us know at the address above so we can assess impact and, if needed, ship a workaround ahead of
the upstream fix.
