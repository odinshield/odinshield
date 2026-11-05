# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# --------------------------------#
## Makefile for the "make" command
## --------------------------------#

# ----- Variables -----
# Single source of truth for the application version. All other places
# (Dockerfile labels, environment/.env APP_VERSION, services.yaml fallback)
# must agree — see tests/Unit/VersionConsistencyTest.php for the drift guard.
ODINSHIELD_VERSION = $(shell cat VERSION 2>/dev/null || echo 0.0.0-dev)
# Build-time pinned dependency versions. The Dockerfile mirrors these as ARG
# defaults; VersionConsistencyTest verifies they don't drift.
FRANKENPHP_VERSION = 1.13.5-php8.5
PAGEFIND_VERSION = v1.5.2
# REUSE tool for `make reuse-lint` (#244). No project dependency; pinned so a new release cannot
# block the gate unannounced. On the host a throwaway container, in the claude container a venv.
REUSE_VERSION = 6.2.0
REUSE_IMAGE = fsfe/reuse:$(REUSE_VERSION)
# Linters for the gaps the PHP/Twig/JS tooling leaves (#264): shell, Dockerfile, Markdown,
# secrets. Single pinned containers instead of Super-Linter (4.2 GB, 3x slower, same findings);
# the claude container carries the same versions as binaries (frankenphp_claude in the Dockerfile).
SHELLCHECK_VERSION = 0.11.0
SHELLCHECK_IMAGE = koalaman/shellcheck-alpine:v$(SHELLCHECK_VERSION)
SHFMT_VERSION = 3.14.1
SHFMT_IMAGE = mvdan/shfmt:v$(SHFMT_VERSION)-alpine
HADOLINT_VERSION = 2.15.1
HADOLINT_IMAGE = hadolint/hadolint:v$(HADOLINT_VERSION)-alpine
MARKDOWNLINT_VERSION = 0.23.3
MARKDOWNLINT_IMAGE = davidanson/markdownlint-cli2:v$(MARKDOWNLINT_VERSION)
GITLEAKS_VERSION = 8.30.1
GITLEAKS_IMAGE = ghcr.io/gitleaks/gitleaks:v$(GITLEAKS_VERSION)
# Symfony CLI for `make lsp-check` in the claude container (>= 5.20.0; the host brings its own).
SYMFONY_CLI_VERSION = 5.22.0
# Biome follows the $schema version in biome.json; the npx fallback is pinned to it as well.
BIOME_VERSION = 2.2.0
# Claude service (frankenphp_claude): Node for Claude Code + the fixers above as binaries.
# The Dockerfile mirrors these as ARG defaults; VersionConsistencyTest verifies they don't drift.
NODE_VERSION = 24.22.1
CLAUDE_CODE_VERSION = 2.1.308
# Headless browser for checking the dev app from the claude container (Playwright + Chromium).
DEV_BROWSER_VERSION = 0.2.9
# Shell scripts by extension plus the extensionless git hook.
SHELL_SCRIPTS = $(shell git ls-files '*.sh') .githooks/pre-push
# Manual content version — independent from ODINSHIELD_VERSION. Bump on manual
# content/structure changes. Mirrored in environment/.env (MANUAL_VERSION) and
# referenced as fallback in config/services.yaml; VersionConsistencyTest enforces.
# MAJOR: chapter restructure · MINOR: new chapter or substantial content · PATCH: corrections
MANUAL_VERSION = 1.5.0
GITEA = 
# The git remote is named `main`, not `origin` (release skill, deploy/gitea-release.py).
GIT_REMOTE = main
# `make ci` (claude container) stamps the commit it checked here; push, ship, build-release and the
# pre-push hook (host) only compare it with HEAD. In the repo root, ignored by git, because the
# container's var/ is its own volume the host never sees.
CI_STAMP = .ci-ok
# HEAD and tree state at the start of `make ci` — the stamp is only written if both still hold at
# the end (a commit during the run would otherwise get a stamp for checks it never saw).
CI_START = var/ci-start
# True when HEAD differs from the remote main only under .claude/memory/ and in MEMORY.md (no
# renames: a file moved into memory would otherwise list only its new path). Empty diff → false.
CI_MEMORY_ONLY = base="$$($(GIT) merge-base HEAD $(GIT_REMOTE)/main 2>/dev/null)" && changed="$$($(GIT) diff --no-renames --name-only "$$base" HEAD)" && [ -n "$$changed" ] && ! printf '%s\n' "$$changed" | grep -qvE '^(\.claude/memory/|MEMORY\.md$$)'
CI_STAMPED = [ "$$(cat $(CI_STAMP) 2>/dev/null)" = "$$($(GIT) rev-parse HEAD)" ]
# make prod-check: what the production image must never contain (dev/Claude tooling, repo
# files for development and the host). Extend when a new tool or file joins the dev side.
PROD_FORBIDDEN_BINS = node npm npx claude composer git make biome shfmt markdownlint-cli2 dev-browser
PROD_FORBIDDEN_PATHS = /app/.git /app/.claude /app/CLAUDE.md /app/MEMORY.md /app/.devcontainer /app/tools /app/.githooks /app/deploy /app/Makefile /app/tests
export PROD_FORBIDDEN_BINS PROD_FORBIDDEN_PATHS

export FRANKENPHP_VERSION
export PAGEFIND_VERSION
export MANUAL_VERSION
export NODE_VERSION CLAUDE_CODE_VERSION DEV_BROWSER_VERSION BIOME_VERSION MARKDOWNLINT_VERSION SHFMT_VERSION
export SHELLCHECK_VERSION HADOLINT_VERSION GITLEAKS_VERSION SYMFONY_CLI_VERSION REUSE_VERSION

# ----- Parameters -----
SHELL = sh
PROJECT = OdinShield

# ----- Executables: host -----
DOCKER = docker
DOCKER_COMP = docker compose
# The dev stack runs as the host user (compose.override.yaml reads these).
DEV_UID ?= $(shell id -u)
DEV_GID ?= $(shell id -g)
export DEV_UID DEV_GID
GIT = git

# ----- Executables: in container -----
# IN_CONTAINER is set in the claude service: it has PHP and every linter of `make ci` itself and
# no Docker socket, so everything runs directly. On the host the same targets go through Docker.
ifdef IN_CONTAINER
DOCKER_PHP =
SHFMT = shfmt
MARKDOWNLINT = markdownlint-cli2
REUSE = reuse
SHELLCHECK = shellcheck
HADOLINT = hadolint
# Empty: tools/secrets-lint.sh then runs the local gitleaks instead of the image.
GITLEAKS_SOURCE =
else
# -T: no TTY. These targets also run without a terminal (the pre-push hook has consumed git's
# stdin), and php has `tty: true`, so exec would otherwise refuse ("stdin is not a terminal").
# `make sh`/`make bash` call exec themselves and stay interactive.
DOCKER_PHP = $(DOCKER_COMP) exec -T php
SHFMT = $(DOCKER) run --rm --user "$$(id -u):$$(id -g)" -v "$$(pwd):/mnt" -w /mnt $(SHFMT_IMAGE) shfmt
MARKDOWNLINT = $(DOCKER) run --rm --user "$$(id -u):$$(id -g)" -v "$$(pwd):/workdir" $(MARKDOWNLINT_IMAGE)
REUSE = $(DOCKER) run --rm --user "$$(id -u):$$(id -g)" -v "$$(pwd):/data" -w /data $(REUSE_IMAGE)
SHELLCHECK = $(DOCKER) run --rm -v "$$(pwd):/mnt" -w /mnt $(SHELLCHECK_IMAGE) shellcheck
HADOLINT = $(DOCKER) run --rm -v "$$(pwd):/mnt" -w /mnt $(HADOLINT_IMAGE) hadolint
GITLEAKS_SOURCE = $(GITLEAKS_IMAGE)
endif
EXEC_PHP = $(DOCKER_PHP) php
COMPOSER = $(DOCKER_PHP) composer
SYMFONY_CONSOLE = $(DOCKER_PHP) bin/console

# ----- Vendor binaries (in container) -----
PHPUNIT = $(DOCKER_PHP) bin/phpunit
PHPSTAN = $(DOCKER_PHP) vendor/bin/phpstan
PHP_CS_FIXER = $(DOCKER_PHP) vendor/bin/php-cs-fixer

# ----- Colors -----
# Callers pass a quoted string ($(call GREEN, "text")). The macro closes its own quotes around it,
# so the caller's quotes join up and the text stays quoted — before, they cancelled each other and
# a "(" in the text was a shell syntax error (make release v2.2.8, 2026-10-29).
GREEN = /bin/echo -e "\x1b[32m\#\# "$(strip $1)"\x1b[0m"
RED = /bin/echo -e "\x1b[31m\#\# "$(strip $1)"\x1b[0m"

# ----- Misc -----
.DEFAULT_GOAL = help
.PHONY        : help
colon = :

## —— Help ——————————————————————————————————————————————————————
.PHONY: help
help: ## Display this help
	@$(call GREEN, "Available commands:")
	@grep -E '(^[a-zA-Z0-9_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— Composer ——————————————————————————————————————————————————————
.PHONY: composer-install
composer-install: ## Install dependencies (in container)
	@$(call GREEN, "Installing dependencies...")
	@$(COMPOSER) install
	$(MAKE) asset-install

.PHONY: composer-update
composer-update: ## Update dependencies (in container)
	@$(call GREEN, "Updating dependencies...")
	@$(COMPOSER) update

.PHONY: composer-recipes
composer-recipes: ## Check for new Symfony Recipes
	@$(call GREEN, "Check for new recipes...")
	@$(COMPOSER) recipes

.PHONY: audit
audit: ## Check composer.lock for known security advisories (runs in make ci)
	@$(COMPOSER) audit --locked

## —— Symfony ————————————————————————————————————————————————————
.PHONY: sf
sf: ## List all Symfony console commands
	@$(SYMFONY_CONSOLE) list

.PHONY: cc
cc: ## Clear the cache
	@$(call GREEN, "Clear Cache...")
	@$(SYMFONY_CONSOLE) cache:clear
	$(MAKE) warmup

.PHONY: warmup
warmup: ## Warmup the cache
	@$(call GREEN, "Warmup cache...")
	@$(SYMFONY_CONSOLE) cache:warmup

.PHONY: asset-install
asset-install: ## Install JS/CSS vendor assets via importmap
	@$(SYMFONY_CONSOLE) importmap:install

.PHONY: asset-compile
asset-compile: ## Compile assets for production
	@$(SYMFONY_CONSOLE) asset-map:compile

.PHONY: asset-lint
asset-lint: ## Lint + format-check JS/CSS assets with Biome (single binary, no node_modules)
	@if command -v biome >/dev/null 2>&1; then \
		biome check assets ; \
	else \
		npx --yes @biomejs/biome@$(BIOME_VERSION) check assets ; \
	fi

.PHONY: asset-fix
asset-fix: ## Auto-fix JS/CSS assets with Biome (lint + format)
	@if command -v biome >/dev/null 2>&1; then \
		biome check --write assets ; \
	else \
		npx --yes @biomejs/biome@$(BIOME_VERSION) check --write assets ; \
	fi

.PHONY: messenger-consume
messenger-consume: ## Consume Messenger transports (runs until interrupted)
	@$(SYMFONY_CONSOLE) messenger:consume --all

.PHONY: purge
purge: ## Purge cache, logs and compiled assets
	@$(call GREEN, "Purge cache — logs — public assets...")
	@$(DOCKER_PHP) rm -rf var/cache/dev var/cache/prod public/assets public/bundles
	@# var/log is the dev_log volume (a mount point, cannot be removed itself) — empty it.
	@$(DOCKER_PHP) find var/log -mindepth 1 -delete

.PHONY: outdated
outdated: ## List outdated importmap packages, direct Composer dependencies and pinned tools (informative, never bumps or fails)
	-@$(SYMFONY_CONSOLE) importmap:outdated
	-@$(COMPOSER) outdated --direct
	-@python3 -B tools/pin-outdated.py shellcheck=$(SHELLCHECK_VERSION) hadolint=$(HADOLINT_VERSION) \
		gitleaks=$(GITLEAKS_VERSION) symfony=$(SYMFONY_CLI_VERSION) reuse=$(REUSE_VERSION) \
		shfmt=$(SHFMT_VERSION) biome=$(BIOME_VERSION) markdownlint=$(MARKDOWNLINT_VERSION) \
		node=$(NODE_VERSION) claude-code=$(CLAUDE_CODE_VERSION) dev-browser=$(DEV_BROWSER_VERSION)

## —— Database ————————————————————————————————————————————————————————————————
.PHONY: migration
migration: ## Generate a diff migration and run it
	@$(call GREEN, "Diff + migrate...")
	@$(SYMFONY_CONSOLE) doctrine:migrations:diff
	@$(SYMFONY_CONSOLE) doctrine:migrations:migrate --no-interaction

.PHONY: migration-dev
migration-dev: ## Drop schema, regenerate one consolidated migration, run it
	@$(call GREEN, "Recreate schema from scratch - DEV ONLY - destroys data...")
	@$(DOCKER_PHP) sh -c 'rm -rf migrations/*'
	@$(SYMFONY_CONSOLE) doctrine:schema:drop --force --full-database
	@$(SYMFONY_CONSOLE) make:migration --no-interaction
	@$(SYMFONY_CONSOLE) doctrine:migrations:migrate --no-interaction
	@$(SYMFONY_CONSOLE) doctrine:migrations:rollup -q

.PHONY: test-db
test-db: ## Create + migrate the test database (app_test) — once per fresh database volume
	@$(call GREEN, "Create + migrate test database...")
	@$(SYMFONY_CONSOLE) doctrine:database:create --env=test --if-not-exists
	@$(SYMFONY_CONSOLE) doctrine:migrations:migrate --env=test --no-interaction

## —— Git ————————————————————————————————————————————————————————————————————————
# Deliberately no longer automated: the guard is installed by hand after reading the template,
# and a Makefile target the claude container can edit must not be what installs it.
.PHONY: hooks
hooks: ## How to install the host guard + pre-push hook (by hand, see docs/internal/CLAUDE-CONTAINER.md)
	@echo "Read tools/host-guard.sh and tools/host-guard-pre-push.sh, then on the host:"
	@echo "  install -m 755 tools/host-guard.sh .git/hooks/host-guard"
	@echo "  install -m 755 tools/host-guard-pre-push.sh .git/hooks/pre-push"
	@echo "  git config core.hooksPath .git/hooks"
	@echo "core.hooksPath now: $$($(GIT) config core.hooksPath || echo '(unset)')"

## —— Translation ————————————————————————————————————————————————————————————————
.PHONY: trans-list
trans-list: ## List missing DE translations
	@$(SYMFONY_CONSOLE) debug:translation --only-missing de

.PHONY: trans-lint
trans-lint: ## Lint translation YAML files
	@$(SYMFONY_CONSOLE) lint:yaml translations

## —— Twig ————————————————————————————————————————————————————————————————
.PHONY: twig-lint
twig-lint: ## Lint Twig templates
	@$(SYMFONY_CONSOLE) lint:twig templates --show-deprecations

## —— Stimulus ————————————————————————————————————————————————————————————————
.PHONY: lint-stimulus
lint-stimulus: ## Check every data-controller/data-action Stimulus reference has a JS file
	@sh tools/lint-stimulus.sh

.PHONY: reuse-lint
reuse-lint: ## Check every file has an SPDX copyright + license (REUSE spec)
	@$(REUSE) lint --lines

## —— Shell / Dockerfile / Markdown / Secrets (#264) ——————————————————————————
.PHONY: shell-lint
shell-lint: ## Lint shell scripts with shellcheck and check shfmt formatting
	@$(SHELLCHECK) $(SHELL_SCRIPTS)
	@$(SHFMT) -d $(SHELL_SCRIPTS)

.PHONY: shell-fix
shell-fix: ## Format shell scripts with shfmt
	@$(SHFMT) -w $(SHELL_SCRIPTS)

.PHONY: docker-lint
docker-lint: ## Lint the Dockerfile with hadolint
	@$(HADOLINT) Dockerfile

.PHONY: md-lint
md-lint: ## Lint Markdown with markdownlint-cli2 (config: .markdownlint-cli2.yaml)
	@$(MARKDOWNLINT) "**/*.md"

.PHONY: md-fix
md-fix: ## Auto-fix Markdown findings with markdownlint-cli2 --fix
	@$(MARKDOWNLINT) --fix "**/*.md"

.PHONY: secrets-lint
secrets-lint: ## Scan the tracked files for secrets with gitleaks (never the untracked .env.local)
	@sh tools/secrets-lint.sh $(GITLEAKS_SOURCE)

.PHONY: lsp-check
lsp-check: ## Symfony-aware diagnostics (routes, templates, translations, services) via symfony lsp:check (host CLI >= 5.20.0)
	@sh tools/lsp-check.sh

## —— Manual ————————————————————————————————————————————————————————————————
.PHONY: manual-index
manual-index: ## Render manual to static HTML and build Pagefind search index
	@$(SYMFONY_CONSOLE) odinshield:manual:render-static
	@if command -v pagefind >/dev/null 2>&1; then \
		pagefind --site .pagefind-build/manual --output-path public/pagefind ; \
	elif command -v npx >/dev/null 2>&1; then \
		npx --yes pagefind --site .pagefind-build/manual --output-path public/pagefind ; \
	elif [ -x ./node_modules/.bin/pagefind ]; then \
		./node_modules/.bin/pagefind --site .pagefind-build/manual --output-path public/pagefind ; \
	else \
		echo "Warning: pagefind binary not found. Install with: npm install -g pagefind" ; \
		echo "         or download a release from https://github.com/CloudCannon/pagefind/releases" ; \
		exit 1 ; \
	fi

## —— Release 🚀 ————————————————————————————————————————————————————————————————
.PHONY: release
release: ## Bump version in the claude container (usage: make release v=2.0.0-rc.8). Writes VERSION + environment/.env, commits, tags, runs make ci.
ifndef IN_CONTAINER
	@echo "make release runs in the claude container (it ends with make ci). On the host: make ship v=X.Y.Z afterwards"; exit 1
endif
	@test -n "$(v)" || { echo "usage: make release v=X.Y.Z[-suffix]"; exit 1; }
	@echo "$(v)" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.+-]+)?$$' || { echo "ERROR: '$(v)' is not a valid SemVer string"; exit 1; }
	@$(GIT) diff --quiet VERSION environment/.env || { echo "ERROR: VERSION or environment/.env have uncommitted changes — clean working tree required"; exit 1; }
	@$(call GREEN, "Bumping version to v$(v)...")
	@echo "$(v)" > VERSION
	@sed -i "s/^APP_VERSION=.*/APP_VERSION=$(v)/" environment/.env
	@$(call GREEN, "Creating commit and tag v$(v)...")
	@$(GIT) add VERSION environment/.env
	@$(GIT) commit -m "RELEASE v$(v)"
	@$(GIT) tag "v$(v)"
	@$(call GREEN, "Running make ci on the release commit (includes the version consistency tests)...")
	@$(MAKE) ci || { echo "ERROR: make ci failed on v$(v) — undo with: git tag -d v$(v) && git reset --hard HEAD~1"; exit 1; }
	@$(call GREEN, "Done. Next on the host: make ship v=$(v) — push of main and tag + build-release.")

# The host half of a release (Andre). The claude container has already run `make release` (commit,
# tag, make ci with stamp); ship checks nothing itself — it refuses unless the tag and the stamp are
# at HEAD. Push, image build and the Gitea entry need network and the token from pass — they only
# ever run here.
.PHONY: ship
ship: ## Release on the host after make release in the container: tag + stamp check -> push main + tag -> build-release (usage: make ship v=X.Y.Z)
ifdef IN_CONTAINER
	@echo "make ship runs on the host (push, registry, token). In the container: make release v=X.Y.Z"; exit 1
endif
	@test -n "$(v)" || { echo "usage: make ship v=X.Y.Z[-suffix]"; exit 1; }
	@echo "$(v)" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.+-]+)?$$' || { echo "ERROR: '$(v)' is not a valid SemVer string"; exit 1; }
	@test "$$($(GIT) branch --show-current)" = main || { echo "ERROR: ship runs on branch main (now: $$($(GIT) branch --show-current))"; exit 1; }
	@test -z "$$($(GIT) status --porcelain)" || { echo "ERROR: working tree not clean"; $(GIT) status --short; exit 1; }
	@$(GIT) rev-parse -q --verify "refs/tags/v$(v)" >/dev/null || { echo "ERROR: no tag v$(v) — run make release v=$(v) in the claude container first"; exit 1; }
	@test "$$($(GIT) rev-parse "v$(v)^{commit}")" = "$$($(GIT) rev-parse HEAD)" || { echo "ERROR: tag v$(v) exists but is not HEAD"; exit 1; }
	@test "$$(cat VERSION)" = "$(v)" || { echo "ERROR: VERSION is $$(cat VERSION), not $(v)"; exit 1; }
	@$(CI_STAMPED) || { echo "ERROR: HEAD has not passed make ci — run make ci in the claude container first"; exit 1; }
	@echo "Pushing $$($(GIT) rev-list --count HEAD --not --remotes) local-only commit(s) to $(GIT_REMOTE)..."
	$(GIT) push $(GIT_REMOTE) HEAD:refs/heads/main
	$(GIT) push $(GIT_REMOTE) refs/tags/v$(v)
	$(MAKE) build-release

# Branch push on the host (Andre); releases go through ship. push-check is read-only and also
# runs in the container: it refuses a detached HEAD or a dirty tree and lists what would go out.
# push does not check itself: `make ci` in the claude container is its own step before it. push
# refuses unless $(CI_STAMP) holds HEAD, then pushes the current branch with upstream — never tags (those belong to ship) —
# and deploy/gitea-pr.py opens the PR into main (skipped on main, an open PR is only printed).
# The pre-push hook holds the same rule for a plain `git push`.
.PHONY: push-check
push-check: ## Show what make push would send (refuses detached HEAD and dirty tree; read-only)
	@test -n "$$($(GIT) branch --show-current)" || { echo "ERROR: detached HEAD — push needs a branch"; exit 1; }
	@test -z "$$($(GIT) status --porcelain)" || { echo "ERROR: working tree not clean"; $(GIT) status --short; exit 1; }
	@if $(GIT) rev-parse -q --verify '@{u}' >/dev/null; then \
		base="@{u}"; echo "Outgoing to $$($(GIT) rev-parse --abbrev-ref '@{u}'):"; \
	else \
		base="$(GIT_REMOTE)/main"; echo "No upstream yet — outgoing compared to $(GIT_REMOTE)/main:"; \
	fi; \
	out="$$($(GIT) log --oneline "$$base..HEAD")"; echo "$${out:-  (no new commits)}"

.PHONY: push
push: ## Push the current branch on the host after make ci in the container: stamp check -> push -u, no tags -> open PR (releases: make ship)
ifdef IN_CONTAINER
	@echo "make push runs on the host (push, hooks). In the container: commit locally, Andre pushes"; exit 1
endif
	@$(MAKE) --no-print-directory push-check
	@$(CI_STAMPED) || { echo "ERROR: HEAD has not passed make ci — run make ci in the claude container first, then make push"; exit 1; }
	@branch="$$($(GIT) branch --show-current)"; \
	echo "$(GIT) push -u $(GIT_REMOTE) $$branch"; \
	$(GIT) push -u $(GIT_REMOTE) "refs/heads/$$branch:refs/heads/$$branch"
	@python3 -B deploy/gitea-pr.py

.PHONY: gitea-release
gitea-release: ## Publish a Gitea release entry for the current VERSION's tag (idempotent — 409 on re-run is treated as success)
	@$(call GREEN, "Publishing Gitea release v$$(cat VERSION)...")
	@python3 -B deploy/gitea-release.py

.PHONY: release-notes
release-notes: ## Print the public release notes without publishing (usage: make release-notes [rev=v2.2.5], default: VERSION tag, else HEAD)
	@python3 -B deploy/gitea-release.py --dry-run $(rev)

.PHONY: release-preflight
release-preflight: ## Read-only check of TLS + Gitea token (from pass) + push permission — first step of build-release
	@$(call GREEN, "Gitea preflight — TLS and token and push permission...")
	@python3 -B deploy/gitea-release.py --check

## —— Docker 🐳 ————————————————————————————————————————————————————————————————
.PHONY: start
start: up-dev ## Alias for up-dev

.PHONY: stop
stop: down ## Alias for down

.PHONY: up-dev
up-dev: ## Start the dev stack (FrankenPHP + worker + Postgres + mailpit)
	@$(call GREEN, "Starting dev stack...")
	@mkdir -p var/symfony-lsp
	$(DOCKER_COMP) up --detach

.PHONY: sbom
sbom: ## Generate the CycloneDX SBOM locally (the image build does this on its own)
	@$(call GREEN, "Generating CycloneDX SBOM...")
	$(DOCKER_PHP) sh -c 'rm -rf /tmp/sbom-tool && cp -r /app/tools/sbom /tmp/sbom-tool && composer --working-dir=/tmp/sbom-tool install --no-cache --no-progress --quiet && composer --working-dir=/tmp/sbom-tool CycloneDX:make-sbom /app/composer.json --output-format=JSON --output-file=/app/sbom.json --omit=dev --omit=plugin --spec-version=1.6 --validate --mc-version="$$(cat VERSION)"'
	@$(SYMFONY_CONSOLE) odinshield:sbom:add-assets sbom.json

.PHONY: build-local
build-local: ## Build prod image locally (cached, no push)
	@$(call GREEN, "Build OdinShield prod image - local cached build...")
	$(DOCKER) build -f Dockerfile --build-arg FRANKENPHP_VERSION=$(FRANKENPHP_VERSION) --build-arg PAGEFIND_VERSION=$(PAGEFIND_VERSION) --build-arg APP_VERSION=$(ODINSHIELD_VERSION) --target=frankenphp_prod -t odinshield:$(ODINSHIELD_VERSION) .

.PHONY: build-release
build-release: ## Preflight + stamp check + clean rebuild + push to Gitea registry + publish Gitea release entry
	$(MAKE) release-preflight
	@test -z "$$($(GIT) status --porcelain)" || { echo "ERROR: working tree not clean"; $(GIT) status --short; exit 1; }
	@$(CI_STAMPED) || { echo "ERROR: HEAD has not passed make ci — run make ci in the claude container first"; exit 1; }
	@$(call GREEN, "Build OdinShield prod image - clean rebuild...")
	$(DOCKER) build --no-cache --pull -f Dockerfile --build-arg FRANKENPHP_VERSION=$(FRANKENPHP_VERSION) --build-arg PAGEFIND_VERSION=$(PAGEFIND_VERSION) --build-arg APP_VERSION=$(ODINSHIELD_VERSION) --target=frankenphp_prod -t odinshield:$(ODINSHIELD_VERSION) .
	$(MAKE) prod-check
	@$(call GREEN, "Tag and push to Gitea...")
	$(DOCKER) tag odinshield:$(ODINSHIELD_VERSION) $(GITEA)odinshield:$(ODINSHIELD_VERSION)
	$(DOCKER) push $(GITEA)odinshield:$(ODINSHIELD_VERSION)
	$(MAKE) gitea-release

.PHONY: prod-check
prod-check: ## Verify the prod image (odinshield:VERSION) carries no dev/Claude tooling — binaries, repo files, require-dev in the SBOM
	@sh tools/prod-check.sh odinshield:$(ODINSHIELD_VERSION)

.PHONY: build
build: build-release ## Alias for build-release

.PHONY: down
down: ## Stop the docker stack
	@$(call GREEN, "Stopping stack...")
	$(DOCKER_COMP) down --remove-orphans

.PHONY: status
status: ## Docker running? Stack state, and how many local commits are not pushed yet
	@$(DOCKER) info > /dev/null 2>&1 || { echo "Docker is not running"; exit 1; }
	@$(DOCKER_COMP) ps
	@echo "Not on any remote: $$($(GIT) rev-list --count HEAD --not --remotes) commit(s) on $$($(GIT) branch --show-current) (local only until Andre pushes)"

# Commits made in the claude container carry the host user's git identity (compose passes these
# on as GIT_AUTHOR_*/GIT_COMMITTER_*).
GIT_USER_NAME ?= $(shell $(GIT) config user.name)
GIT_USER_EMAIL ?= $(shell $(GIT) config user.email)
export GIT_USER_NAME GIT_USER_EMAIL

# make claude passes the claude-bot token (scope write:issue only) from pass as GITEA_ISSUE_TOKEN,
# by name only (-e without a value), so it never lands in a file or on a command line.

# Colours: the host's TERM is passed through (Docker would set plain xterm), and COLORTERM=truecolor
# is set outright — Windows Terminal renders 24-bit colour but does not export it.

# The host's shared learnings skill, mounted read-only as a personal skill when it exists (no copy
# into the repo); without it make claude starts as before.
CLAUDE_LEARNINGS_DIR ?= $(HOME)/ai/homelab/skills/learnings

.PHONY: claude
# The claude service only depends_on database; tests that send mail need mailer, the browser checks
# need php/worker (and netguard, whose network they share). So the host brings the whole dev stack up
# first. Prerequisite, not recipe: an ifdef in the recipe could not skip it, and inside the container
# `start` would fail on the missing Docker socket before the "already inside" message.
claude: $(if $(IN_CONTAINER),,start) ## Start the dev stack, then Claude Code in its own container (profile claude: non-root, no Docker socket, LAN blocked); args via c="…"
ifdef IN_CONTAINER
	@echo "Already inside the claude container"; exit 1
endif
	@test -e .claude/settings.local.json || echo '{}' > .claude/settings.local.json
	@GITEA_ISSUE_TOKEN=$$(pass show gitea-claude-bot/git.example.org 2>/dev/null | head -1); \
	[ -n "$$GITEA_ISSUE_TOKEN" ] || echo "Hint: no Gitea issue token (pass locked or gitea-claude-bot/git.example.org missing) - starting without"; \
	export GITEA_ISSUE_TOKEN; \
	$(DOCKER_COMP) --profile claude run --rm --build -e GITEA_ISSUE_TOKEN -e TERM -e COLORTERM=truecolor \
		$(if $(wildcard $(CLAUDE_LEARNINGS_DIR)/.),-v $(CLAUDE_LEARNINGS_DIR):/home/claude/.claude/skills/learnings:ro) \
		claude $(if $(c),claude $(c))

.PHONY: claude-shell
claude-shell: ## Open bash in a fresh claude container (same firewall and user as make claude)
	@test -e .claude/settings.local.json || echo '{}' > .claude/settings.local.json
	$(DOCKER_COMP) --profile claude run --rm --build claude bash

.PHONY: sh
sh: ## Open a shell in the php container
	@$(DOCKER_COMP) exec php sh

.PHONY: bash
bash: ## Open bash in the php container
	@$(DOCKER_COMP) exec php bash

.PHONY: logs
logs: ## Tail logs of all services
	@$(DOCKER_COMP) logs --tail=0 --follow

## —— Coding standards ✨ ——————————————————————————————————————————————————————
.PHONY: fix
fix: ## Apply every auto-fixer: php-cs-fixer + Biome + shfmt + markdownlint (container or host)
	$(MAKE) fix-php
	$(MAKE) asset-fix
	$(MAKE) shell-fix
	$(MAKE) md-fix

.PHONY: lint-yaml
lint-yaml: ## Lint the YAML under config/
	@$(SYMFONY_CONSOLE) lint:yaml config

.PHONY: verify
verify: ## Quick gate, part of make ci (no stamp): lint-yaml + lint-php + stan + twig-lint + trans-lint + lint-stimulus + test (runs in the claude container)
	$(MAKE) lint-yaml
	$(MAKE) lint-php
	$(MAKE) stan
	$(MAKE) twig-lint
	$(MAKE) trans-lint
	$(MAKE) lint-stimulus
	$(MAKE) test

# ci is the one full gate and runs only in the claude container, which carries every linter
# (frankenphp_claude), so Claude sees the result. On success with a clean working tree it stamps
# the commit into $(CI_STAMP); the host never checks itself — make push, make ship, build-release and
# the pre-push hook refuse a commit without that stamp. A dirty tree gets no stamp — what was
# checked would not be the commit. Same for a HEAD that moved while ci ran: the stamp goes to the
# HEAD recorded at the start, and only if it is still HEAD and the tree was clean at both ends.
# A clean branch that differs from $(GIT_REMOTE)/main only in agent memory carries main's code
# unchanged — it gets secrets-lint + reuse-lint instead of the full run (memory is where a secret
# would most likely slip in). Anything else, a dirty tree or a stale $(GIT_REMOTE)/main → full run.
.PHONY: ci
ci: ## Full gate (claude container): audit + asset-lint + reuse/shell/docker/md/secrets-lint + lsp-check + verify; memory-only branch: secrets + reuse; stamps .ci-ok
ifndef IN_CONTAINER
	@echo "make ci runs in the claude container (Claude sees the result there). The host only checks its stamp: make push / make ship"; exit 1
endif
	@mkdir -p $(dir $(CI_START))
	@{ $(GIT) rev-parse HEAD; [ -z "$$($(GIT) status --porcelain)" ] && echo clean || echo dirty; } > $(CI_START)
	@if [ "$$(sed -n 2p $(CI_START))" = clean ] && $(CI_MEMORY_ONLY); then \
		echo "ci: only .claude/memory/ and MEMORY.md differ from $(GIT_REMOTE)/main — secrets-lint + reuse-lint"; \
		$(MAKE) secrets-lint && $(MAKE) reuse-lint; \
	else \
		$(MAKE) ci-checks; \
	fi
	@start_head="$$(sed -n 1p $(CI_START))"; start_tree="$$(sed -n 2p $(CI_START))"; rm -f $(CI_START); \
	if [ "$$($(GIT) rev-parse HEAD)" != "$$start_head" ]; then \
		rm -f $(CI_STAMP); \
		echo "HEAD moved during ci — no stamp"; \
	elif [ "$$start_tree" != clean ] || [ -n "$$($(GIT) status --porcelain)" ]; then \
		rm -f $(CI_STAMP); \
		echo "ci ok — working tree not clean, no stamp"; \
	else \
		echo "$$start_head" > $(CI_STAMP); \
		echo "ci ok — stamped $$start_head into $(CI_STAMP)"; \
	fi

# The full check list of make ci. No help entry: on its own it records nothing and stamps nothing.
.PHONY: ci-checks
ci-checks:
	$(MAKE) audit
	$(MAKE) asset-lint
	$(MAKE) reuse-lint
	$(MAKE) shell-lint
	$(MAKE) docker-lint
	$(MAKE) md-lint
	$(MAKE) secrets-lint
	$(MAKE) lsp-check
	$(MAKE) verify

.PHONY: stan
stan: ## Run PHPStan in the container
	@$(PHPSTAN) analyse -c phpstan.dist.neon --memory-limit 1G

.PHONY: lint-php
lint-php: ## Check code style with php-cs-fixer (no changes)
	$(PHP_CS_FIXER) fix --allow-risky=yes --dry-run --config=php-cs-fixer.php

.PHONY: fix-php
fix-php: ## Fix code style with php-cs-fixer
	$(PHP_CS_FIXER) fix --allow-risky=yes --config=php-cs-fixer.php

.PHONY: test
test: ## Run the full test suite
	@$(PHPUNIT)

.PHONY: test-unit
test-unit: ## Run only Unit tests (fast)
	@$(PHPUNIT) tests/Unit

.PHONY: test-functional
test-functional: ## Run only Functional tests
	@$(PHPUNIT) tests/Functional

.PHONY: test-coverage
test-coverage: ## Run tests with HTML coverage report in var/coverage
	@$(DOCKER_PHP) sh -c 'XDEBUG_MODE=coverage bin/phpunit --coverage-html var/coverage'
	@$(call GREEN, "Coverage report: var/coverage/index.html")
