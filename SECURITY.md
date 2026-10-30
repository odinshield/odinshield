<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Security Policy

OdinShield is vulnerability management software. Taking reports about *its own* security
seriously is the least we owe the people who run it.

## Supported versions

Only the **latest released version** receives security fixes. There are no long-term support
branches — the project is small enough that upgrading is the fix. Releases are listed under
[Releases](../../releases); image tags carry no leading `v` (git tag `v2.1.0` → image `2.1.0`).

## Reporting a vulnerability

**Please do not open a public issue for security findings.**

Two confidential routes, either is fine:

1. **GitHub private vulnerability reporting** — the *Report a vulnerability* button under the
   repository's Security tab. Preferred, because the whole exchange stays in one place.
2. **Email** to `security@odinshield.net` with `[OdinShield Security]` in the subject.

Useful in a report, as far as you can supply it:

- affected version (shown in the application footer, or the `VERSION` file)
- what an attacker gains — read access to other users' data, privilege escalation, code execution …
- the smallest set of steps that reproduces it
- the deployment shape, if it matters: reverse proxy in front, roles involved, single or multiple instances

A rough impact assessment helps, but do not hold a report back because you are unsure how severe
something is. A finding you are not certain about is still worth sending.

## What happens next

- **Acknowledgement:** we aim to confirm receipt within a few working days. This is a small
  project without an on-call rotation — it is an intention, not a service level.
- **Assessment:** we tell you whether we can reproduce it and how we rate it.
- **Fix:** shipped in a regular release. Which release depends on severity.
- **Credit:** you are named in the release notes if you want to be, and not if you do not.

We ask for **coordinated disclosure**: give us a chance to ship a fix before you publish. If you
have a deadline in mind, say so in your first message and we will tell you honestly whether it is
realistic rather than let it lapse in silence.

## Scope

**In scope** is everything in this repository: the application, the deployment templates
(`compose.deploy.yaml`, `compose.frontproxy.yaml`, `.env.deploy.example`) and the container image
built from `Dockerfile`.

**Out of scope**, because they are not ours to fix:

- vulnerabilities in third-party dependencies — report them upstream; if OdinShield's use of a
  dependency makes an upstream issue exploitable *here*, that is in scope and we want to hear it
- an installation someone has misconfigured (missing TLS, an exposed database port, a weak
  `APP_SECRET`) — unless our documentation led them there, which we would count as our bug
- findings that require an already-compromised host or an already-privileged account, with no
  privilege boundary crossed

## Security-relevant design decisions

Decisions in the code that exist for security reasons carry a `// SEC-NNN:` comment. If you change
code next to one, read the comment first — it usually records an attack that was considered.
