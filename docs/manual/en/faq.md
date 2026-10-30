<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Frequently Asked Questions (FAQ)

Answers to common questions about your daily work with OdinShield.

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>Vulnerabilities</h4>
        <p>Questions about visibility, status workflow, and risk assessment.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Inventory</h4>
        <p>Questions about software creation, reviews, and EOL dates.</p>
    </div>
    <div class="manual-feature-card">
        <h4>CSAF Imports</h4>
        <p>Questions about automation and matching.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Account & Team</h4>
        <p>Questions about login, permissions, and roles.</p>
    </div>
</div>

## Vulnerabilities & Workflow

<details>
    <summary>Why am I no longer seeing this vulnerability?</summary>
    <div>
        Possible reasons:
        <ul>
            <li>You are no longer responsible (someone else was assigned).</li>
            <li>You were removed from a user group.</li>
            <li>The vulnerability was <strong>closed</strong>, and your list is filtered by <strong>Active</strong> – for example because you opened it from a dashboard card. By default, the list shows all statuses. To see closed vulnerabilities, select <strong>All Statuses</strong>, <strong>Inactive</strong>, or <strong>Closed</strong> in the status filter.</li>
        </ul>
    </div>
</details>

<details>
    <summary>Why can't I close a vulnerability?</summary>
    <div>
        Final closing is reserved for the <strong>ISO</strong> (four-eyes principle). Set the status to <strong>Resolved</strong> and ask your ISO for approval.
    </div>
</details>

<details>
    <summary>Why can't I delete a vulnerability?</summary>
    <div>
        Deleting, like closing, is reserved for the <strong>ISO</strong>. If a finding does not apply to an asset group, set the occurrence to <strong>Not Affected</strong> instead of removing the group (see <a href="schwachstellen">Manage vulnerabilities</a>). A genuine mis-entry is deleted by the ISO.
    </div>
</details>

<details>
    <summary>The import says "Security patch available" – why is the vulnerability still Open?</summary>
    <div>
        Because the import never changes the status itself. It leaves the note and the flag; confirming that the patch is installed stays with you. Set the occurrence to <strong>Resolved</strong> once the update is live.
    </div>
</details>

<details>
    <summary>Do I have to handle each occurrence individually?</summary>
    <div>
        Usually yes: each occurrence is its own instance in an asset group. Remediation may look different per group (for example, a patch or a configuration change).
        As an <strong>ISO</strong>, you can set all occurrences that aren't done yet of one or more vulnerabilities to the same status in one step. Use the <strong>Set status</strong> bulk action in the vulnerability list. You choose <strong>Resolved</strong>, <strong>Risk Accepted</strong>, or <strong>Deferred</strong>, which closes or defers the vulnerability (see <a href="schwachstellen#bulk-action-several-vulnerabilities-at-once">Bulk action</a>).
    </div>
</details>

## Inventory & Software

<details>
    <summary>Why can't I create new software?</summary>
    <div>
        Creating software is reserved for Software Owners and ISOs. As the person responsible for existing software, you may edit it.
    </div>
</details>

<details>
    <summary>What happens when I create a successor version?</summary>
    <div>
        OdinShield asks you per asset group whether it stays on the old version or migrates to the new one. This creates a seamless history.
    </div>
</details>

## Automation (CSAF)

<details>
    <summary>Why am I not receiving automatic vulnerabilities?</summary>
    <div>
        Check these five things:
        <ul>
            <li>The software's <strong>maintenance source</strong> is <strong>Import</strong>, and both software and vendor are <strong>Active</strong>.</li>
            <li>The software is assigned to at least one <strong>asset group</strong>.</li>
            <li><strong>Vendor Key</strong> and <strong>Product Key</strong> read the way advisories name the product – otherwise add an alias name.</li>
            <li>The advisory is rated at least <strong>Medium</strong> – lower-rated advisories are not imported.</li>
            <li>The advisory was published or updated <strong>after</strong> the software was added or corrected. The import does not check older advisories again on its own – your administration can catch up on them with <code>odinshield:csaf:rewind</code>.</li>
        </ul>
        A version without a leading number is never the cause: it matches every advisory. How the comparison works is described in <a href="inventar">Maintaining the inventory</a>.
    </div>
</details>

<details>
    <summary>Do I still need to maintain CPE IDs?</summary>
    <div>
        No. The CPE field is gone since version 2.2.0. When an advisory carries a CPE ID, OdinShield uses only its vendor and product part and compares it with your keys.
    </div>
</details>

<details>
    <summary>Why was a vulnerability reported although my patch level is already newer?</summary>
    <div>
        Because the inventory usually keeps the version at major/minor level ("7.6") while the advisory states the bound at patch level ("below 7.6.4"). OdinShield reports it anyway – it would rather report once too often than miss one. In the <strong>Advisory</strong> tab, the text under <strong>Description from the source</strong> ends with the section <code>## Matched because</code> (English), which names the compared range. If your patch level is past that bound, set the occurrence to <strong>Not Affected</strong> or record the version with its patch level – see <a href="inventar">Maintaining the inventory</a>.
    </div>
</details>

<details>
    <summary>Where do the EPSS Score and "Actively exploited in the wild" come from?</summary>
    <div>
        From the original sources FIRST.org (EPSS) and CISA KEV, once a day for every vulnerability with a CVE ID that is not yet in a terminal status – including those entered by hand.
    </div>
</details>

## What's Next?

If a technical term is unclear, our lexicon will help you:

<div class="manual-next-action">
    <div class="manual-next-action-label">Recommended Next Step</div>
    <h3 class="manual-next-action-title">Glossary & Terms</h3>
    <p class="manual-next-action-text">Look up technical terms like CVE, EPSS, or effective risk in our glossary.</p>
    <a href="glossar" class="manual-next-action-button">
        To Chapter: Glossary
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
