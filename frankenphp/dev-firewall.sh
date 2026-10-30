#!/bin/bash

# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# Egress firewall for the dev containers that run repository code: the `netguard` service (whose
# network namespace php and worker share) and the `claude` service. Sets the rules as root, then
# runs the command as FIREWALL_RUN_AS with no capabilities left and no-new-privs, so nothing in
# the container can change the rules afterwards. Any failure aborts the start.
#
# Rule: the internet is open, private networks are closed — the dev stack needs no internal
# service, and a Compose container otherwise reaches the whole LAN by NAT. Allowed inside
# private space: the container's own Compose network (database, mailer, the other services),
# except its gateway (that is the Docker host itself), plus explicit exceptions. Names still
# resolve via Docker's DNS; only connections are blocked.
#
# Environment:
#   FIREWALL_RUN_AS               uid:gid to run the command as (required)
#   FIREWALL_ALLOW                space-separated host:port exceptions (claude: git01:443)
#   FIREWALL_ALLOW_GATEWAY_PORTS  space-separated TCP ports open on the Docker host (Xdebug 9003)
#   FIREWALL_READY_FILE           touched once the rules are in place (healthcheck of netguard)
set -euo pipefail

PRIVATE_V4="10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 169.254.0.0/16 100.64.0.0/10" # snapshot:allow
PRIVATE_V6="fc00::/7 fe80::/10"
run_as=${FIREWALL_RUN_AS:?FIREWALL_RUN_AS (uid:gid) must be set}

if [ "$(id -u)" != 0 ]; then
    echo "dev-firewall: must start as root to set the firewall (it drops to $run_as itself)" >&2
    exit 1
fi

# The host's extra CAs, where mounted (claude): git01 is signed by the internal root CA.
if compgen -G '/usr/local/share/ca-certificates/host/*.crt' >/dev/null; then
    update-ca-certificates >/dev/null 2>&1
fi

own_net=$(ip -4 route show scope link | awk 'NR == 1 { print $1 }')
gateway=$(ip -4 route show default | awk 'NR == 1 { print $3 }')

iptables -F OUTPUT
iptables -A OUTPUT -o lo -j ACCEPT
iptables -A OUTPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
allowed=
for entry in ${FIREWALL_ALLOW:-}; do
    host=${entry%:*}
    port=${entry##*:}
    ip=$(getent ahostsv4 "$host" | awk 'NR == 1 { print $1 }' || true)
    if [ -n "$ip" ]; then
        iptables -A OUTPUT -d "$ip" -p tcp --dport "$port" -j ACCEPT
        allowed="$allowed $host($ip):$port"
    else
        echo "dev-firewall: $host does not resolve — no exception for it" >&2
    fi
done
if [ -n "$gateway" ]; then
    for port in ${FIREWALL_ALLOW_GATEWAY_PORTS:-}; do
        iptables -A OUTPUT -d "$gateway" -p tcp --dport "$port" -j ACCEPT
        allowed="$allowed host:$port"
    done
    iptables -A OUTPUT -d "$gateway" -j REJECT --reject-with icmp-admin-prohibited
fi
if [ -n "$own_net" ]; then
    iptables -A OUTPUT -d "$own_net" -j ACCEPT
fi
for net in $PRIVATE_V4; do
    iptables -A OUTPUT -d "$net" -j REJECT --reject-with icmp-admin-prohibited
done

# IPv6 only matters when the container has a v6 route out; Docker's default network has none.
if [ -n "$(ip -6 route show default 2>/dev/null)" ]; then
    ip6tables -F OUTPUT
    ip6tables -A OUTPUT -o lo -j ACCEPT
    ip6tables -A OUTPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
    for net in $PRIVATE_V6; do
        ip6tables -A OUTPUT -d "$net" -j REJECT
    done
fi

# Self-test: a private address must be refused locally (the packet never leaves the container).
# A timeout would mean the REJECT rule is not in effect.
if timeout 3 bash -c 'exec 3<>/dev/tcp/10.255.255.254/443' 2>/dev/null; then
    echo "dev-firewall: private network reachable — firewall not effective, aborting" >&2
    exit 1
elif [ $? = 124 ]; then
    echo "dev-firewall: private address timed out instead of being refused — aborting" >&2
    exit 1
fi

# claude's var/ is its own anonymous volume (compose.override.yaml) and starts empty: create the
# directories logs and the Symfony cache go to while still root. /app/var/log is the dev_log
# volume shared with php and worker; netguard mounts it only for this chown, which therefore runs
# before the ready file — php and worker wait for it and log there as DEV_UID.
mkdir -p /app/var/log /app/var/cache
chown "$run_as" /app/var/log /app/var/cache

if [ -n "${FIREWALL_READY_FILE:-}" ]; then
    touch "$FIREWALL_READY_FILE"
fi
echo "dev-firewall: egress locked (own network ${own_net:-?}; allowed:${allowed:- none}); running as $run_as"
# An interactive run (claude) hands its terminal to the user it drops to, as a login does: Docker
# creates it root:tty 0620, so hooks (claude-bell) could not write the bell to it otherwise.
if terminal=$(tty 2>/dev/null); then
    chown "$run_as" "$terminal"
fi
exec setpriv --reuid="${run_as%:*}" --regid="${run_as#*:}" --clear-groups \
    --inh-caps=-all --bounding-set=-all --no-new-privs -- "$@"
