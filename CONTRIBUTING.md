<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Contributing to OdinShield

Thank you for considering a contribution. Bug reports, feature ideas and pull requests are all
welcome. **Security findings are not** — please follow [SECURITY.md](SECURITY.md) instead and
never open a public issue for them.

## Developer Certificate of Origin (DCO)

OdinShield uses the [Developer Certificate of Origin 1.1](https://developercertificate.org/)
instead of a Contributor License Agreement. There is **no CLA** to sign.

By adding a `Signed-off-by` line to a commit, you certify that you wrote the change or otherwise
have the right to submit it under the project's licence (AGPL-3.0-or-later, see
[LICENSE.md](LICENSE.md)). Your copyright stays with you; the contribution is licensed under the
same terms as the rest of the project.

Every commit in a pull request needs the line, with your real name and the email address of the
commit author:

```text
Signed-off-by: Jane Doe <jane.doe@example.org>
```

Git adds it for you with `-s`:

```bash
git commit -s -m "FIX CSAF-Import: …"
```

Forgot it? Add it to the commits of your branch and force-push:

```bash
git rebase --signoff main      # all commits since main
git commit --amend --signoff   # only the last commit
git push --force-with-lease
```

A pull request check (`DCO`) verifies that every commit carries a sign-off matching its author.
A pull request cannot be taken over while it fails.

## How a pull request gets in

The repository on GitHub is a **release snapshot**: development happens in an internal repository,
and each release is published here as one commit. That has one consequence for you:

- Pull requests are reviewed and discussed here as usual.
- An accepted change is applied to the internal repository with your authorship and your
  `Signed-off-by` preserved, and appears here with the next release.
- The pull request is then closed with a note naming the release that contains it — it is
  not merged on GitHub, because the next snapshot would replace the content anyway.

## Before you open a pull request

- For anything larger than a small fix, open an issue first so we can agree on the approach.
- Keep pull requests focused: one concern per pull request.
- Commit subjects start with a prefix — `FIX`, `ADD` or `DOCS` — followed by the area, e.g.
  `FIX CSAF-Import: Versionsvergleich bei Suffixen`. The prefix decides the release notes.
- The code follows the `@Symfony` coding standard and PHPStan level 8; new behaviour comes with
  tests. The development setup and all checks are described in [CLAUDE.md](CLAUDE.md)
  (section *Commands*); run `make verify` before you push.
- New source files carry the two-line SPDX header used throughout the project
  (`make reuse-lint` checks it).
