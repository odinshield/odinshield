<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Glossary

Terms in OdinShield explained briefly and in plain language.

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>CVE</h4>
        <p><strong>Common Vulnerabilities and Exposures.</strong> The global ID for a specific security vulnerability.</p>
    </div>
    <div class="manual-feature-card">
        <h4>CPE</h4>
        <p><strong>Common Platform Enumeration.</strong> The product ID many security advisories carry. OdinShield uses only its vendor and product part.</p>
    </div>
    <div class="manual-feature-card">
        <h4>CVSS</h4>
        <p><strong>Common Vulnerability Scoring System.</strong> The standard score (0-10) for the severity of a vulnerability.</p>
    </div>
</div>

## Terms from A to Z

### Asset Group

A logical group of IT assets (e.g., "Webserver"). Risk properties like criticality and exposure are defined here.

### Audit Trail

The change history of the logged objects, every change with person and timestamp – per object on the **History** tab, across all objects in the audit timeline (administrators only, see [Team & Responsibilities](team)).

### BSI

The German Federal Office for Information Security. A key source for CSAF security advisories in OdinShield.

### CISA KEV

The "Known Exploited Vulnerabilities" catalogue of the US agency CISA — vulnerabilities that are demonstrably exploited. The **Actively exploited in the wild** flag mirrors this list; how and how often it is set is described in [Manage vulnerabilities](schwachstellen).

### CPE

**Common Platform Enumeration.** The product ID in security advisories (`cpe:/o:fortinet:fortios:7.6.4`). When an advisory carries a CPE, OdinShield compares only its vendor and product part with Vendor Key and Product Key; the inventory has no CPE field of its own (any more).

### CSAF

The machine-readable format for security advisories from authorities and vendors. OdinShield uses CSAF for automatic import.

### Effective Risk

A number (1-480) that describes how urgent a remediation really is. It combines the severity of the gap with the importance of your infrastructure; if the gap is flagged *Actively exploited in the wild*, the value doubles.

### End-of-Life (EOL)

The date when support for a software ends. OdinShield warns you in advance of this date.

### EPSS

**Exploit Prediction Scoring System.** A percentage computed by FIRST.org that estimates how likely a vulnerability is to be exploited within the next 30 days. OdinShield fetches it from FIRST.org once a day. When a vulnerability bundles several CVEs, the highest value applies.

### ISO

The Information Security Officer. They have full access and close vulnerabilities based on the four-eyes principle.

### NVD

The National Vulnerability Database. The primary source for CVSS scores worldwide. Its CPE Search helps you spell the Vendor Key and Product Key correctly – see [Maintaining the inventory](inventar).

### Occurrence

The concrete link between a vulnerability and an asset group. A vulnerability can have many occurrences – each with its own status (for example one **Resolved**, another **Risk Accepted**).

### OT

**Operational Technology** – controllers, sensors and other technology that drives physical processes. OdinShield keeps OT in the same inventory as IT, without a model of its own. How to model an OT device is shown in [Maintaining the inventory](inventar).

### Status

Vulnerabilities and occurrences each have their **own** status. The occurrence status describes the situation per asset group; the vulnerability status describes the overall finding. See the chapter [Manage vulnerabilities](schwachstellen).

## What's Next?

Ready to start? Return to the beginning to take your first steps in OdinShield:

<div class="manual-next-action">
    <div class="manual-next-action-label">Recommended Next Step</div>
    <h3 class="manual-next-action-title">Back to the Start</h3>
    <p class="manual-next-action-text">Start with the onboarding guide and set up your team and inventory.</p>
    <a href="erste-schritte" class="manual-next-action-button">
        To Chapter: Getting Started
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
