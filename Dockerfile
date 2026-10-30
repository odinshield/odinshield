#syntax=docker/dockerfile:1

# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
#
# SPDX-License-Identifier: AGPL-3.0-or-later

ARG FRANKENPHP_VERSION=1.13.4-php8.5
# Node for Claude Code — only the frankenphp_claude stage copies from it (see the end of the file).
ARG NODE_VERSION=24.22.1
FROM dunglas/frankenphp:${FRANKENPHP_VERSION} AS frankenphp_upstream
FROM node:${NODE_VERSION}-trixie-slim AS node_runtime

# ── base ──────────────────────────────────────────────────────────────────────
FROM frankenphp_upstream AS frankenphp_base
ARG APP_VERSION

LABEL org.opencontainers.image.title="OdinShield" \
      org.opencontainers.image.description="Vulnerability management system" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.vendor="m³ Gommlich" \
      org.opencontainers.image.url="https://www.odinshield.net" \
      org.opencontainers.image.source="https://github.com/odinshield/odinshield" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# hadolint ignore=DL3008
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends \
		acl \
		file \
		gettext \
		git \
		tzdata
	install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		pdo_pgsql \
		xsl \
		zip
	rm -rf /var/lib/apt/lists/*
EOF

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

# The PHP one-liner is full of double quotes; JSON (exec) form would bury it in escapes. The shell
# form is deliberate — bash is in the image either way.
# hadolint ignore=DL3025
HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# ── dev ───────────────────────────────────────────────────────────────────────
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
# Default for running without compose; compose.override.yaml refines this to "num 1\nwatch".
ENV FRANKENPHP_WORKER_CONFIG=watch

# The dev stack runs as the host user (compose.override.yaml `user:`), so files written into the
# bind-mounted repo belong to that user, not root. Two paths must be writable for it: the
# entrypoint writes 99-timezone.ini into app.conf.d on every start, and /app/var seeds the
# anonymous var volume (an anonymous volume takes the owner of the image directory it covers).
ARG DEV_UID=1000
ARG DEV_GID=1000

# iptables + iproute2 for dev-firewall: the services that run repository code (netguard, whose
# network php and worker share, and claude) lock egress down as root, then drop to DEV_UID.
# hadolint ignore=DL3008
RUN <<-EOF
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	install-php-extensions xdebug
	git config --system --add safe.directory /app
	apt-get update
	apt-get install -y --no-install-recommends iptables iproute2
	rm -rf /var/lib/apt/lists/*
EOF

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/dev-firewall.sh /usr/local/bin/dev-firewall

# After the COPY: `COPY --link` recreates the target directory root-owned in its own layer.
RUN <<-EOF
	mkdir -p /app/var
	chown "${DEV_UID}:${DEV_GID}" /app/var "$PHP_INI_DIR/app.conf.d"
EOF

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# ── prod builder ──────────────────────────────────────────────────────────────
FROM frankenphp_base AS frankenphp_prod_builder
ARG APP_VERSION

ENV APP_ENV=prod
# Bake the version into the prod environment so `composer dump-env prod` picks
# it up into .env.local.php and Symfony's app_version parameter resolves to it.
ENV APP_VERSION=${APP_VERSION}

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# SEC-MD01: Pagefind binary, version-pinned and integrity-verified.
# Version is pinned in PAGEFIND_VERSION (never use "latest" — supply-chain risk).
# #198: the archive is checked against the PAGEFIND_SHA256_* values pinned HERE, not against the
# .sha256 sidecar of the same release — a sidecar only catches transfer errors, a manipulated
# release would ship a matching one. When bumping PAGEFIND_VERSION, refresh both hashes: take
# them from the release's .sha256 sidecars AND recompute `sha256sum` over the downloaded archives;
# both must agree. A version without matching hashes fails the build on purpose.
# v1.5.2 hashes verified both ways on 2026-10-20.
# Note: repo moved from CloudCannon/pagefind to Pagefind/pagefind; filename now includes version prefix.
ARG PAGEFIND_VERSION=v1.5.2
ARG PAGEFIND_SHA256_X86_64=afb824a9e7f64905a934900481cea5be679c03975e527329e0e5e6cc70f5feda
ARG PAGEFIND_SHA256_AARCH64=f50ec608bcbf431cebd84e0efa3a5b041ee63df2ad81f138023e0cfd2f509424
# hadolint ignore=DL3008,DL4006
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends ca-certificates curl
	ARCH="$(uname -m)"
	case "$ARCH" in
		x86_64) PF_ARCH="x86_64-unknown-linux-musl"; PF_SHA256="${PAGEFIND_SHA256_X86_64}" ;;
		aarch64) PF_ARCH="aarch64-unknown-linux-musl"; PF_SHA256="${PAGEFIND_SHA256_AARCH64}" ;;
		*) echo "Unsupported architecture for Pagefind: $ARCH"; exit 1 ;;
	esac
	BASE="https://github.com/Pagefind/pagefind/releases/download/${PAGEFIND_VERSION}"
	curl -fsSL -o /tmp/pagefind.tar.gz "${BASE}/pagefind-${PAGEFIND_VERSION}-${PF_ARCH}.tar.gz"
	echo "${PF_SHA256}  /tmp/pagefind.tar.gz" | sha256sum -c -
	tar --no-same-owner -xz -C /usr/local/bin -f /tmp/pagefind.tar.gz
	rm -f /tmp/pagefind.tar.gz
	chmod +x /usr/local/bin/pagefind
	rm -rf /var/lib/apt/lists/*
EOF

COPY --link composer.* symfony.* ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

COPY --link --exclude=frankenphp/ . ./

RUN <<-EOF
	mkdir -p var/cache var/log var/share
	composer dump-autoload --classmap-authoritative --no-dev
	composer dump-env prod
	composer run-script --no-dev post-install-cmd
	if [ -f importmap.php ]; then
		php bin/console asset-map:compile
	fi
	# Render manual to static HTML and build Pagefind search index
	php bin/console odinshield:manual:render-static --force
	# #275: pagefind adds to an existing output dir without pruning stale fragments, and a local
	# public/pagefind/ enters the context (only .gitignore excludes it). Start from an empty dir so
	# the index never depends on the build machine. Not via .dockerignore — see the manual-search
	# breakage that caused for .pagefind-build.
	rm -rf public/pagefind
	pagefind --site .pagefind-build/manual --output-path public/pagefind
	chmod +x bin/console
	chmod -R g=u var
	sync
EOF

# Collect shared libraries needed by FrankenPHP and PHP extensions for the minimal prod image.
# hadolint ignore=DL3008,SC3054,DL4006
RUN <<-'EOF'
	apt-get update
	apt-get install -y --no-install-recommends libtree
	mkdir -p /tmp/libs
	BINARIES=(frankenphp php file)
	for target in $(printf '%s\n' "${BINARIES[@]}" | xargs -I{} which {}) \
		$(find "$(php -r 'echo ini_get("extension_dir");')" -maxdepth 2 -name "*.so"); do
		libtree -pv "$target" 2>/dev/null | grep -oP '(?:── )\K/\S+(?= \[)' | while IFS= read -r lib; do
			[ -f "$lib" ] && cp -n "$lib" /tmp/libs/
		done
	done
	rm -rf /var/lib/apt/lists/*
EOF

# ── SBOM ──────────────────────────────────────────────────────────────────────
# SBOM of the delivered artefact (#82). Generated from the builder's /app because composer.lock
# and importmap.php are excluded from the production image — at runtime the data is gone.
# #198: in a stage of its own. The generator is a Composer plugin, i.e. code that runs with
# write access to /app; here that /app is a throwaway copy, and only sbom.json leaves the stage.
# The generator lives in a throwaway project (tools/sbom/, pinned by its composer.lock) so it
# never becomes a dependency of the application and never appears in its own output.
FROM frankenphp_prod_builder AS frankenphp_sbom

RUN <<-EOF
	cp -r /app/tools/sbom /tmp/sbom-tool
	composer --working-dir=/tmp/sbom-tool install --no-cache --no-progress
	composer --working-dir=/tmp/sbom-tool CycloneDX:make-sbom /app/composer.json \
		--output-format=JSON --output-file=/app/sbom.json \
		--omit=dev --omit=plugin --spec-version=1.6 --validate \
		--mc-version="${APP_VERSION}"
	php bin/console odinshield:sbom:add-assets /app/sbom.json
EOF

# ── prod ──────────────────────────────────────────────────────────────────────
FROM debian:13-slim AS frankenphp_prod
ARG APP_VERSION

LABEL org.opencontainers.image.title="OdinShield" \
      org.opencontainers.image.description="Vulnerability management system" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.vendor="m³ Gommlich" \
      org.opencontainers.image.url="https://www.odinshield.net" \
      org.opencontainers.image.source="https://github.com/odinshield/odinshield" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=prod
ENV PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"
ENV OPENSSL_CONF=/etc/ssl/openssl.cnf
# OpenSSL's built-in default (/usr/lib/ssl/cert.pem) does not exist in debian-slim; without
# this, native PHP streams (fopen/file_get_contents over https, SMTP STARTTLS) fail certificate
# verification. curl is unaffected (own default path). Upstream dunglas/symfony-docker#953.
ENV SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt
ENV XDG_CONFIG_HOME=/config
ENV XDG_DATA_HOME=/data

# FrankenPHP + PHP runtimes
COPY --from=frankenphp_prod_builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
COPY --from=frankenphp_prod_builder /usr/local/bin/php /usr/local/bin/php
COPY --from=frankenphp_prod_builder /usr/local/bin/docker-php-entrypoint /usr/local/bin/docker-php-entrypoint
COPY --from=frankenphp_prod_builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=frankenphp_prod_builder /tmp/libs /usr/lib

# PHP configuration
COPY --from=frankenphp_prod_builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=frankenphp_prod_builder /usr/local/etc/php/php.ini /usr/local/etc/php/php.ini
COPY --from=frankenphp_prod_builder /usr/local/etc/php/app.conf.d /usr/local/etc/php/app.conf.d

# Caddy configuration
COPY --from=frankenphp_prod_builder /etc/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

# TLS + crypto
COPY --from=frankenphp_prod_builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=frankenphp_prod_builder /etc/ssl/openssl.cnf /etc/ssl/openssl.cnf

# MIME detection (Symfony uses file/libmagic)
COPY --from=frankenphp_prod_builder /usr/bin/file /usr/bin/file
COPY --from=frankenphp_prod_builder /usr/lib/file/magic.mgc /usr/lib/file/magic.mgc

# Timezone data (entrypoint writes date.timezone ini from TZ env var)
COPY --from=frankenphp_prod_builder /usr/share/zoneinfo /usr/share/zoneinfo

RUN <<-EOF
	mkdir -p /data/caddy /config/caddy
	# app.conf.d must be writable so the entrypoint can write 99-timezone.ini
	chown -R www-data:www-data /data /config /usr/local/etc/php/app.conf.d
	# Remove setuid/setgid bits
	find / -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null || true
EOF

COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

# App code — var/ copied separately to set www-data:0 for arbitrary-UID runtimes (OpenShift).
# Files needed only during the build are excluded here rather than deleted in the builder,
# keeping the builder layer clean and the exclusion logic in one declarative place.
COPY --link \
    --exclude=var \
    --exclude=assets \
    --exclude=.pagefind-build \
    --exclude=environment/.env \
    --exclude=composer.json \
    --exclude=composer.lock \
    --exclude=symfony.lock \
    --exclude=importmap.php \
    --exclude=tools \
    --from=frankenphp_prod_builder /app /app
COPY --link --from=frankenphp_sbom /app/sbom.json /app/sbom.json
COPY --chown=www-data:0 --from=frankenphp_prod_builder /app/var /app/var
RUN chmod g=u /app/var

# DL3066: www-data is created by the Debian base image, so the name always resolves inside the
# container; a numeric id would silently break if the base image ever renumbered it.
# hadolint ignore=DL3066
USER www-data
WORKDIR /app

ENTRYPOINT ["docker-entrypoint"]

# The PHP one-liner is full of double quotes; JSON (exec) form would bury it in escapes. The shell
# form is deliberate — bash is in the image either way.
# hadolint ignore=DL3025
HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# ── claude ────────────────────────────────────────────────────────────────────
# Claude Code in the dev stack (compose service `claude`, docs/internal/CLAUDE-CONTAINER.md).
# Same PHP as the dev stage plus Node, Claude Code and every tool `make fix` and `make ci` need —
# the full gate runs here, the host only checks its stamp. Last in the file and derived from _dev
# only: no prod stage ever builds from here, and build-release targets frankenphp_prod. Versions
# mirror the Makefile (VersionConsistencyTest); every download is checked against its sha256.
FROM frankenphp_dev AS frankenphp_claude
ARG CLAUDE_CODE_VERSION=2.1.308
ARG BIOME_VERSION=2.2.0
ARG MARKDOWNLINT_VERSION=0.23.3
ARG DEV_BROWSER_VERSION=0.2.9
ARG SHFMT_VERSION=3.14.1
ARG SHFMT_SHA256_AMD64=76e77641faa025814b77f153b29796b8e6fa2fca03e0c76a691608b86c7ea7bf
ARG SHFMT_SHA256_ARM64=5f2db09dae91fca848f7adbdd014632e921a383863a2ad7e0450ad3aba0c6489
ARG SHELLCHECK_VERSION=0.11.0
ARG SHELLCHECK_SHA256_AMD64=b7af85e41cc99489dcc21d66c6d5f3685138f06d34651e6d34b42ec6d54fe6f6
ARG SHELLCHECK_SHA256_ARM64=68a8133197a50beb8803f8d42f9908d1af1c5540d4bb05fdfca8c1fa47decefc
ARG HADOLINT_VERSION=2.15.1
ARG HADOLINT_SHA256_AMD64=c7187db94eeeeca956519a6af171adc31453941a1e777961f6e680f697c8c507
ARG HADOLINT_SHA256_ARM64=f6198ef8090f404dbb771abfee086eb8c48ac177f30da7fd3510aca35b344b5d
ARG GITLEAKS_VERSION=8.30.1
ARG GITLEAKS_SHA256_AMD64=551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb
ARG GITLEAKS_SHA256_ARM64=e4a487ee7ccd7d3a7f7ec08657610aa3606637dab924210b3aee62570fb4b080
ARG SYMFONY_CLI_VERSION=5.22.0
ARG SYMFONY_CLI_SHA256_AMD64=26af8671c2ea0cefd72c3629513e190af7c2e239e81e0ea84842716f4db3a585
ARG SYMFONY_CLI_SHA256_ARM64=4c8589ff6e76e505842a35beb5b75724c3ec648cddc9ba23ce1530fa3a87cf1e
ARG REUSE_VERSION=6.2.0
ARG TARGETARCH
ARG DEV_UID=1000
ARG DEV_GID=1000

# Inherited from frankenphp_base already; restated because the RUN below pipes (hadolint DL4006).
SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

COPY --from=node_runtime /usr/local/bin/node /usr/local/bin/node
COPY --from=node_runtime /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm

# dev-browser's Chromium: outside the claude user's home, read-only for it, found at run time too.
ENV PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright

# Debian's python3 follows the base image; reuse itself is pinned (DL3008 for the apt line).
# hadolint ignore=DL3008
RUN <<-EOF
	ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
	ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
	# npm 11 skips install scripts unless allowed; dev-browser's postinstall fetches its CLI binary.
	npm install --global --no-fund --no-audit --allow-scripts=dev-browser \
		"@anthropic-ai/claude-code@${CLAUDE_CODE_VERSION}" \
		"@biomejs/biome@${BIOME_VERSION}" \
		"markdownlint-cli2@${MARKDOWNLINT_VERSION}" \
		"dev-browser@${DEV_BROWSER_VERSION}"
	npm cache clean --force
	arch="${TARGETARCH:-amd64}"
	case "$arch" in
		amd64)
			shfmt_sha="${SHFMT_SHA256_AMD64}"
			sc_arch=x86_64 sc_sha="${SHELLCHECK_SHA256_AMD64}"
			hl_arch=x86_64 hl_sha="${HADOLINT_SHA256_AMD64}"
			gl_arch=x64 gl_sha="${GITLEAKS_SHA256_AMD64}"
			sf_sha="${SYMFONY_CLI_SHA256_AMD64}"
			;;
		arm64)
			shfmt_sha="${SHFMT_SHA256_ARM64}"
			sc_arch=aarch64 sc_sha="${SHELLCHECK_SHA256_ARM64}"
			hl_arch=arm64 hl_sha="${HADOLINT_SHA256_ARM64}"
			gl_arch=arm64 gl_sha="${GITLEAKS_SHA256_ARM64}"
			sf_sha="${SYMFONY_CLI_SHA256_ARM64}"
			;;
		*) echo "unsupported arch ${TARGETARCH}" >&2; exit 1 ;;
	esac
	# fetch <url> <sha256> <file>: download, and fail the build unless the checksum matches.
	fetch() { curl -fsSLo "$3" "$1"; echo "$2  $3" | sha256sum -c -; }
	fetch "https://github.com/mvdan/sh/releases/download/v${SHFMT_VERSION}/shfmt_v${SHFMT_VERSION}_linux_${arch}" \
		"$shfmt_sha" /usr/local/bin/shfmt
	fetch "https://github.com/hadolint/hadolint/releases/download/v${HADOLINT_VERSION}/hadolint-linux-${hl_arch}" \
		"$hl_sha" /usr/local/bin/hadolint
	chmod 755 /usr/local/bin/shfmt /usr/local/bin/hadolint
	dl=$(mktemp -d)
	fetch "https://github.com/koalaman/shellcheck/releases/download/v${SHELLCHECK_VERSION}/shellcheck-v${SHELLCHECK_VERSION}.linux.${sc_arch}.tar.gz" \
		"$sc_sha" "$dl/shellcheck.tar.gz"
	tar -xzf "$dl/shellcheck.tar.gz" -C "$dl"
	install -m 755 "$dl/shellcheck-v${SHELLCHECK_VERSION}/shellcheck" /usr/local/bin/shellcheck
	fetch "https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}/gitleaks_${GITLEAKS_VERSION}_linux_${gl_arch}.tar.gz" \
		"$gl_sha" "$dl/gitleaks.tar.gz"
	tar -xzf "$dl/gitleaks.tar.gz" -C "$dl" gitleaks
	install -m 755 "$dl/gitleaks" /usr/local/bin/gitleaks
	fetch "https://github.com/symfony-cli/symfony-cli/releases/download/v${SYMFONY_CLI_VERSION}/symfony-cli_linux_${arch}.tar.gz" \
		"$sf_sha" "$dl/symfony.tar.gz"
	tar -xzf "$dl/symfony.tar.gz" -C "$dl" symfony
	install -m 755 "$dl/symfony" /usr/local/bin/symfony
	rm -rf "$dl"
	# reuse is Python-only; a venv keeps it out of Debian's externally managed system Python.
	apt-get update
	apt-get install -y --no-install-recommends python3 python3-venv
	rm -rf /var/lib/apt/lists/*
	python3 -m venv /opt/reuse
	/opt/reuse/bin/pip install --no-cache-dir "reuse==${REUSE_VERSION}"
	ln -s /opt/reuse/bin/reuse /usr/local/bin/reuse
	groupadd --gid "${DEV_GID}" claude
	useradd --uid "${DEV_UID}" --gid "${DEV_GID}" --create-home --shell /bin/bash claude
	mkdir -p /home/claude/.claude
	chown "${DEV_UID}:${DEV_GID}" /home/claude/.claude
	# dev-browser's Playwright runtime lives in the claude user's ~/.dev-browser (hence HOME). Not
	# `dev-browser install`: it also fetches full Chromium (364 MB), never started headless. Any
	# dev-browser command writes the runtime's package.json there and stops — runtime missing.
	HOME=/home/claude dev-browser status || true
	test -f /home/claude/.dev-browser/package.json
	HOME=/home/claude npm install --prefix /home/claude/.dev-browser --no-fund --no-audit
	pw=/home/claude/.dev-browser/node_modules/.bin/playwright
	# Only the headless shell, into PLAYWRIGHT_BROWSERS_PATH; install-deps adds the system
	# libraries plus fontconfig and fonts (without them every glyph renders blank).
	"$pw" install --only-shell chromium
	DEBIAN_FRONTEND=noninteractive "$pw" install-deps chromium
	rm -rf /var/lib/apt/lists/* /home/claude/.npm
	chown -R "${DEV_UID}:${DEV_GID}" /home/claude/.dev-browser
EOF

# Status line and bell hook, switched on by the image's managed settings (statusLine
# "claude-statusline", hooks Notification/Stop "claude-bell") — not by the claude_config volume, so
# a fresh volume has them too and they never fire twice.
COPY --link --chmod=755 frankenphp/claude-statusline.mjs /usr/local/bin/claude-statusline
COPY --link --chmod=755 frankenphp/claude-bell.sh /usr/local/bin/claude-bell
COPY --link --chmod=644 frankenphp/claude-managed-settings.json /etc/claude-code/managed-settings.json
# After the COPY: `COPY --link --chmod=644` creates /etc/claude-code with 644 too — no search bit,
# so the claude user gets EACCES on the file and on managed-settings.d.
RUN chmod 755 /etc/claude-code

ENV IN_CONTAINER=1
ENV HOME=/home/claude
ENV CLAUDE_CONFIG_DIR=/home/claude/.claude
ENV DISABLE_AUTOUPDATER=1

# Starts as root for the firewall only (dev-firewall, from the dev stage); it hands over to
# FIREWALL_RUN_AS (compose: DEV_UID = `claude`) with every capability dropped. No FrankenPHP
# here, so no healthcheck.
HEALTHCHECK NONE
ENTRYPOINT ["dev-firewall"]
CMD ["claude"]
