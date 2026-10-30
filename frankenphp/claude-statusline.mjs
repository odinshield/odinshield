#!/usr/bin/env node

// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

// Claude Code status line for the claude container (same look as the host one): reads the session
// JSON on stdin and prints one line — model · effort │ context bar │ rate limits │ repo:branch.
// Node only (the image has no Python for this), no git subprocess: the branch comes from .git/HEAD.

import { readFileSync, statSync } from 'node:fs';
import { basename, dirname, join, resolve } from 'node:path';

const RESET = '\x1b[0m';
const MAIN = '\x1b[97m';
const SECONDARY = '\x1b[37m';
const DIM = '\x1b[90m';
const SEPARATOR = `${DIM} │ ${RESET}`;
const BAR_WIDTH = 10;
const PARTIALS = ' ▏▎▍▌▋▊▉';

const paint = (colour, text) => `${colour}${text}${RESET}`;

const isNumber = (value) => typeof value === 'number' && Number.isFinite(value);

// Colours the rounded value, so "50%" is never green and "80%" never yellow.
function traffic(pct) {
    const shown = Math.round(pct);
    if (shown < 50) {
        return '\x1b[1;92m';
    }

    return shown < 80 ? '\x1b[1;93m' : '\x1b[1;91m';
}

function tokens(value) {
    return value >= 1000 ? `${Math.round(value / 1000)}k` : String(Math.round(value));
}

function modelSegment(data) {
    const name = data.model?.display_name;
    const effort = data.effort?.level;
    if (!name && !effort) {
        return null;
    }
    let text = paint(MAIN, name || '?');
    if (effort) {
        text += paint(SECONDARY, ` · ${effort}`);
    }

    return text;
}

function contextSegment(data) {
    const window = data.context_window ?? {};
    if (!isNumber(window.used_percentage)) {
        return paint(SECONDARY, 'Kontext: –');
    }
    const pct = Math.min(100, Math.max(0, window.used_percentage));
    const colour = traffic(pct);
    const eighths = Math.round((pct / 100) * BAR_WIDTH * 8);
    const full = Math.floor(eighths / 8);
    const partial = eighths % 8;
    let filled = '█'.repeat(full);
    if (partial > 0) {
        filled += PARTIALS[partial];
    }
    const empty = '░'.repeat(BAR_WIDTH - full - (partial > 0 ? 1 : 0));
    let text = `${paint(colour, filled)}${paint(DIM, empty)} ${paint(colour, `${Math.round(pct)}%`)}`;
    if (isNumber(window.total_input_tokens) && isNumber(window.context_window_size)) {
        text += paint(SECONDARY, ` ${tokens(window.total_input_tokens)}/${tokens(window.context_window_size)}`);
    }

    return text;
}

// resets_at may arrive as epoch seconds, epoch milliseconds or an ISO string.
function resetTime(value) {
    let date = null;
    if (isNumber(value)) {
        date = new Date(value < 1e12 ? value * 1000 : value);
    } else if (typeof value === 'string' && value !== '') {
        date = /^\d+$/.test(value) ? new Date(Number(value) * 1000) : new Date(value);
    }
    if (!date || Number.isNaN(date.getTime())) {
        return null;
    }

    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

function rateSegment(data) {
    const parts = [];
    const fiveHour = data.rate_limits?.five_hour;
    if (isNumber(fiveHour?.used_percentage)) {
        let text = paint(traffic(fiveHour.used_percentage), `5h ${Math.round(fiveHour.used_percentage)}%`);
        const reset = resetTime(fiveHour.resets_at);
        if (reset) {
            text += paint(SECONDARY, ` ↻${reset}`);
        }
        parts.push(text);
    }
    const sevenDay = data.rate_limits?.seven_day;
    if (isNumber(sevenDay?.used_percentage)) {
        parts.push(paint(traffic(sevenDay.used_percentage), `7d ${Math.round(sevenDay.used_percentage)}%`));
    }

    return parts.length > 0 ? parts.join(paint(SECONDARY, ' · ')) : null;
}

function readText(path) {
    try {
        return readFileSync(path, 'utf8').trim();
    } catch {
        return null;
    }
}

// Walks up from dir to the first .git; a worktree or submodule has a file "gitdir: …" instead.
function findGit(dir) {
    let current = resolve(dir);
    for (;;) {
        const candidate = join(current, '.git');
        let stat = null;
        try {
            stat = statSync(candidate);
        } catch {
            stat = null;
        }
        if (stat?.isDirectory()) {
            return { root: current, gitDir: candidate };
        }
        if (stat?.isFile()) {
            const match = /^gitdir:\s*(.+)$/m.exec(readText(candidate) ?? '');
            if (match) {
                return { root: current, gitDir: resolve(current, match[1].trim()) };
            }
        }
        const parent = dirname(current);
        if (parent === current) {
            return null;
        }
        current = parent;
    }
}

function branchOf(gitDir) {
    const head = readText(join(gitDir, 'HEAD'));
    if (!head) {
        return null;
    }
    const ref = /^ref:\s*(.+)$/.exec(head);
    if (ref) {
        return ref[1].trim().replace(/^refs\/heads\//, '');
    }

    return /^[0-9a-f]{7,}$/i.test(head) ? head.slice(0, 7) : null;
}

function repoSegment(data) {
    const dir = data.workspace?.current_dir || data.cwd;
    if (!dir) {
        return null;
    }
    const git = findGit(dir);
    const name = data.workspace?.repo?.name || basename(git ? git.root : resolve(dir));
    const branch = git ? branchOf(git.gitDir) : null;
    if (!name) {
        return null;
    }

    return paint(MAIN, name) + (branch ? paint(SECONDARY, `:${branch}`) : '');
}

let data;
try {
    data = JSON.parse(readFileSync(0, 'utf8'));
} catch {
    process.stdout.write('statusline: kein JSON\n');
    process.exit(0);
}
if (data === null || typeof data !== 'object') {
    data = {};
}

const segments = [modelSegment, contextSegment, rateSegment, repoSegment]
    .map((segment) => {
        try {
            return segment(data);
        } catch {
            return null;
        }
    })
    .filter(Boolean);

process.stdout.write(`${segments.length > 0 ? segments.join(SEPARATOR) : paint(SECONDARY, 'Claude')}\n`);
