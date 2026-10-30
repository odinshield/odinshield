#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# Weekly CrowdSec hub refresh.
# Fetches metadata, upgrades installed collections/scenarios/parsers,
# and reloads the engine so new rules take effect immediately.
#
# Install:
#   sudo install -m 0755 crowdsec-hub-upgrade.sh /usr/local/bin/
#   sudo install -m 0644 crowdsec-hub-upgrade.cron /etc/cron.d/crowdsec-hub-upgrade
#
# Logs:
#   journalctl -t crowdsec-hub-upgrade
#   /var/log/crowdsec-hub-upgrade.log (rotated by logrotate if configured)

set -euo pipefail

CONTAINER="${CROWDSEC_CONTAINER:-crowdsec}"
LOG_TAG="crowdsec-hub-upgrade"
LOG_FILE="/var/log/${LOG_TAG}.log"

log() {
    local msg="$*"
    echo "$(date -Is) ${msg}" | tee -a "${LOG_FILE}"
    logger -t "${LOG_TAG}" "${msg}" || true
}

if ! docker inspect --format '{{.State.Running}}' "${CONTAINER}" 2>/dev/null | grep -q true; then
    log "ERROR: container '${CONTAINER}' is not running — aborting"
    exit 1
fi

log "Starting hub update for container '${CONTAINER}'"

docker exec "${CONTAINER}" cscli hub update 2>&1 | tee -a "${LOG_FILE}"

# `cscli hub upgrade` returns non-zero only on real errors; "nothing to do" is exit 0.
if docker exec "${CONTAINER}" cscli hub upgrade 2>&1 | tee -a "${LOG_FILE}"; then
    log "Hub upgrade completed"
else
    log "ERROR: hub upgrade failed — see log above"
    exit 1
fi

# Reload triggers re-read of any new YAML; container stays up.
# Custom local scenarios in /etc/crowdsec/scenarios/odinshield-*.yaml are untouched.
if docker kill --signal=SIGHUP "${CONTAINER}" >/dev/null 2>&1; then
    log "Sent SIGHUP to reload configuration"
else
    log "WARNING: SIGHUP failed — falling back to container restart"
    docker restart "${CONTAINER}" >/dev/null
    log "Container restarted"
fi

log "Done"
