<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Manage vulnerabilities

Vulnerabilities are the heart of OdinShield. In this chapter, you will learn how to systematically manage security gaps from initial capture to successful remediation.

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>Capture</h4>
        <p>Create security gaps manually or import them automatically via CSAF interfaces.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Link</h4>
        <p>Connect vulnerabilities with software and asset groups to define <strong>occurrences</strong>.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Evaluate</h4>
        <p>Prioritize the <strong>effective risk</strong> and define responsibilities within the team.</p>
    </div>
</div>

## The Vulnerability Lifecycle

Every vulnerability moves through a clearly defined process. Follow this path to keep your IT landscape secure.

<div class="manual-stepper">
    <div class="manual-step">
        <div class="manual-step-number">1</div>
        <h3 class="manual-step-title">Capture & Assignment</h3>
        <div class="manual-step-content">
            Create a new vulnerability. Assign a <strong>responsible person</strong>. If left blank, you will automatically be assigned as responsible.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">2</div>
        <h3 class="manual-step-title">Define Occurrences</h3>
        <div class="manual-step-content">
            Select the affected <strong>asset groups</strong> – and, if a product from your inventory is affected, the matching <strong>software</strong>. OdinShield now calculates the effective risk for each occurrence.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">3</div>
        <h3 class="manual-step-title">Remediation & Status</h3>
        <div class="manual-step-content">
            Once work begins, set the status to <strong>In Progress</strong>. After the technical solution, mark the occurrence as <strong>Resolved</strong>.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">4</div>
        <h3 class="manual-step-title">Approval (ISO Check)</h3>
        <div class="manual-step-content">
            The Information Security Officer (ISO) verifies the remediation and closes the vulnerability permanently (<strong>Closed</strong>) – only possible once <strong>all occurrences</strong> are done: <strong>Resolved</strong>, <strong>Risk Accepted</strong>, or <strong>Not Affected</strong>.
        </div>
    </div>
</div>

## Understanding Status: Two Levels

In OdinShield, status exists on **two levels** that answer different questions:

- **Occurrence status** – "What was done on *this* asset group?" (the operational reality)
- **Vulnerability status** – "What is the overall state of the finding?" (the higher-level assessment)

The two may differ – and that is intentional: the same flaw can be **Resolved** on asset group A and **Risk Accepted** on group B. The vulnerability status summarises the overall finding; it is **not** derived automatically from the occurrences.

**Occurrence status** (per asset group):

| Status | Meaning |
|--------|---------|
| **Open** | Affects the group, nothing done yet. |
| **In Progress** | Remediation on this group is underway. |
| **Resolved** | Technically fixed (patch or workaround effective). |
| **Risk Accepted** | Consciously carried risk, no fix planned. |
| **Not Affected** | Checked – this group is not vulnerable. |
| **Deferred** | Treatment deliberately postponed (e.g. maintenance window). |

**Vulnerability status** (overall finding):

| Status | Meaning |
|--------|---------|
| **Open** | Recorded, work not yet started. |
| **In Progress** | At least one group is being worked on. |
| **Resolved** | All occurrences are done – the ISO approval is still pending. |
| **Risk Accepted** | The overall risk is carried org-wide. |
| **Deferred** | The overall finding was postponed. |
| **Closed** | The ISO has given final approval (four-eyes principle). |

> **Important:** A vulnerability can only be set to a final status (**Resolved**, **Risk Accepted**, **Closed**) once **all** of its occurrences are done. A **deferred** or still-open occurrence prevents this. **Closed** is reserved for the ISO. This applies to closing and reopening alike: if you edit a closed vulnerability without the ISO role, the status is locked, but you can still edit the other fields. **Closed** exists only at the vulnerability level, not on an individual occurrence. **Deleting** a vulnerability is reserved for the ISO as well; every other role can only move a finding into a final status, not remove it. Conversely, **Not Affected** exists only on the individual occurrence: a finding as a whole is never "not affected", or it would not have been recorded.

### Using "Not Affected" correctly

A product runs on five asset groups according to the inventory – but the vulnerability only bites with a particular configuration, and three groups are demonstrably not vulnerable. For those three, set the occurrence to **Not Affected**.

This is clearly distinct from two neighbours:

- **Not Risk Accepted.** There, the group *is* affected and the risk is consciously carried. With **Not Affected** there is no risk to carry.
- **Not deselecting.** You could also remove the asset group from the vulnerability. That destroys the evidence that somebody looked – and the group reappears on the next import run, because the importers only ever add groups. **Not Affected** persists.

Two effects worth knowing:

- A not-affected occurrence does **not** contribute to the vulnerability's effective risk. If the only group is not affected, the vulnerability's risk drops to 0.
- A not-affected occurrence does **not** count as remediated (the "x of y resolved" display ignores it), but it does not block closing the vulnerability either.

Use the occurrence's notes field for the justification – for example "module not compiled in" or "feature disabled on these hosts". That is the actual evidence.

## Choosing Affected Asset Groups: Two Paths

Which asset groups you may select depends on whether you specify software. OdinShield switches between the two paths automatically – a hint below the selection field always tells you which one is active.

### With software – the inventory sets the choice

As soon as you select software, the list of asset groups narrows to those where that product actually runs according to the inventory. This makes it impossible for a vulnerability to claim a product is affected somewhere it is not even installed.

If a group is missing from the list, that points to a gap in the inventory – not to a problem with the form. To close it:

1. Open **Inventory → Asset Groups** and select the group in question.
2. Add the product in the **Software** field.
3. Return to the vulnerability – the group is now selectable.

### Without software – free choice

If you specify no software, you may freely select any active asset group. This path is there on purpose, for findings that cannot sensibly be inventoried:

- a vulnerability in a single library on a Linux system whose version you do not know,
- a pentest finding on a component that is not (yet) in the inventory,
- a report where at first only the affected system is known.

The inventory is a **product inventory**, not a complete bill of materials of every installed library. You do not have to record every library just to be able to document a vulnerability.

> **Good to know:** Even without software, the vulnerability gets a full effective risk score. The calculation draws on the criticality, exposure and data classification of the **asset group** – not on the software. What you lose without an inventory reference is only the automatic import: without it, no advisory can find this vulnerability on its own – the automatic import only works for software whose maintenance source is **Import** and whose keys and version are maintained.

### When an occurrence is marked "Not backed by inventory"

If someone later changes the inventory – because a product is removed from an asset group, for instance – occurrences already recorded are kept. They are **not** deleted, because status, remediation date and the full change history hang off them. Instead, a notice label appears in the **Status per Asset Group** table.

The label is not an error but a question to you: if the finding was correct at the time and has since been dealt with, leave it standing as evidence. If the assignment was wrong, deselect the asset group in the form.

## Set Responsibilities

Every vulnerability needs a person responsible for driving the remediation.

- **Assignment:** You can assign a person directly when capturing or later via a dropdown.
- **Notification:** The assigned person receives an email (if enabled) and sees the count in the **bell** icon (top bar).
- **Auto-Assignment:** If the field is left blank, you will automatically be assigned as the responsible person.
- **Due date:** The optional date by which remediation should be complete. You enter it in the form and see it in the detail view. OdinShield attaches no automation to it – no status change, no reminder – it is your planning aid for daily prioritisation (see [Tips](tipps)). The vulnerability list does not show the date as a column.

## Understanding Effective Risk

OdinShield automatically calculates how urgently action is required. The <strong>effective risk</strong> combines the technical severity of the gap with the criticality of your asset group.

| Range | Class | Action |
|-------|-------|--------|
| **≥ 120** | Critical | Immediate action required |
| **≥ 60** | High | Remediation within a few days |
| **≥ 20** | Medium | Planned remediation in the coming weeks |
| **< 20** | Low | Routine maintenance |

> **Important:** A risk always applies **per occurrence**. The same gap can be critical on a public web server, but only low in an isolated test network.

### Active exploitation doubles the risk

If a vulnerability is flagged **Actively exploited in the wild**, OdinShield doubles its effective risk on every occurrence. A score of 32 (Medium) becomes 64 (High). The score range is therefore 1 to 480; the class thresholds in the table above stay the same.

- **Deviation from BSI-CS 093:** the BSI formula does not consider whether a gap is already being attacked; OdinShield adds this deliberately.
- **Source doesn't matter:** the doubling applies as soon as the flag is set – whether by the daily CISA KEV check or by hand in the form, for example after a BSI warning.
- **Removing it:** untick the flag in the form and the risk drops again immediately. If one of the entry's CVEs is still on the KEV list and the entry is not closed out, the next daily run sets the flag – and with it the doubling – again.
- **EPSS is not included:** the EPSS score is an estimate that changes daily and is missing for many CVEs. It helps your own assessment but does not change the effective risk.

## Adjusting the severity of an imported vulnerability

The CSAF import derives the severity from the advisory's CVSS score, or from the advisory's own rating when there is no CVSS score. If that rating does not fit your environment – for example because the affected service is not reachable anywhere – rate the vulnerability yourself. You need permission to edit the vulnerability (see the **Set Responsibilities** section above).

1. Open the vulnerability for editing and choose a different **Severity**. The **Source value** is shown below the field.
2. Under **Justification of the different rating**, enter why you deviate. A different rating cannot be saved without a justification.
3. Save. The effective risk is recalculated with your severity.

- **New advisory versions:** your rating is kept. Only if a new version rates higher than the previous version **and** higher than you did does the source value apply again. The import then appends an English note ("Severity override lifted …") to the **Remediation Comment**, with your previous rating and justification, so you can re-assess. A rating above the source value is never lowered; once the source reaches your rating, the justification is dropped.
- **CVSS score:** it remains the source's measurement and cannot be edited for imported vulnerabilities.
- **Traceability:** the detail view shows **Rated manually** with the source value and your justification; who changed it and when is on the **History** tab.
- **Back to the source:** choose the source value again; the justification is then dropped.
- **Manually entered vulnerabilities:** there is no source value. You change **Severity** and **CVSS score** freely, no justification needed.

## Keep your own notes separate from imported text

For automatically imported vulnerabilities, the descriptive text comes from the source — a CSAF advisory from the BSI or from a vendor. That text lives in the **Advisory** tab of the detail view under **Description from the source** and is **not editable** there: every import run rewrites it in full, so any change would be lost on the next run.

Your own text belongs in the **Description** field on the *Details* tab. No import overwrites that field — whatever you write there stays.

The tab only appears when there is import data for the entry. Purely manual vulnerabilities do not have it.

> **The import never sets "Resolved" by itself.** When an advisory reports a fix for your product, the entry receives the **Security patch available** flag and – on an entry that already exists – a note in the **Remediation Comment** field – the status stays untouched. Whether the patch is actually installed is always confirmed by a person. Status, description, responsible person, due date and remediation priority are left alone. The **CSAF import** does write into two fields, though: it appends the fix note to **Remediation Comment**, and as soon as a new advisory version appears it prepends a block to your text in **Mitigation**. Your own entry is kept but moves down. The fix note is English ("Fix available per advisory …"); the mitigation block carries a German header line ("--- CSAF-Update … ---") followed by the advisory's original text, usually English. Nothing is translated. The fix note names the version from which the advisory lists the product as fixed ("fixed from 7.6.4").
>
> **The vendor corrects itself:** does a new advisory version list your software as *not affected* and no longer as affected by any vulnerability of that version? Then the import appends an English note ("Vendor reports not affected per advisory …") to **Remediation Comment**, once per advisory version – but only for entries that are not yet closed out (not for **Resolved**, **Risk Accepted** or **Closed**) and only if the corrected advisory reaches the import threshold (default: severity **Medium**). Status, occurrences and software links stay, nothing is closed automatically. Check whether the all-clear applies to your environment, then set the occurrences to **Not Affected** yourself. Once all occurrences are done, the ISO can close the entry. An advisory that lists your software only as not affected never creates an entry.

<details>
    <summary>Background: provenance data in the "Advisory" tab</summary>
    <div>
        Next to the text, the tab shows where the entry came from and how current it is:
        <ul>
            <li><strong>CSAF sources:</strong> provider, document number, advisory version and the time of the last sync. A rising version means the source revised its advisory.</li>
            <li><strong>Match reason:</strong> the text under <strong>Description from the source</strong> ends with a section <code>## Matched because</code> (English, written by the import). It names, per software, the reported version range, the compared inventory version and the reason for the hit – for instance that your version is kept at major/minor level and overlaps the range. If the advisory's version text could not be read, the original text is shown here and the advisory counts for all versions.</li>
            <li><strong>Bundled CVE IDs:</strong> a single CSAF advisory often covers several CVEs — here you see all of them.</li>
        </ul>
        Extremely long advisory texts are truncated. A plain-text marker of the form
        <code>[… truncated at 131,072 characters — full advisory: …]</code> is then appended, carrying the address of
        the full original. That address is not clickable — copy it into your browser. Some sources do not provide one;
        in that case use the advisory URL on the <em>Details</em> tab.
    </div>
</details>

## Work Efficiently: Filter & Search

Use the list view to focus on what matters most:

- **Assigned to me:** Instantly find your personal tasks.
- **Risk Sorting:** By default, the most pressing topics are at the top.
- **EPSS and active exploitation:** In the **CVE ID** column, the **EPSS** value in percent appears below the CVE. An asterisk (\*) after the value means the entry bundles several CVEs and the highest EPSS value among them is shown. Hover over the value to see how many CVEs it bundles. The detail view lists all of them under **Bundled CVE IDs**. A flame icon next to the **effective risk** means **Actively exploited** – also for entries without a CVE. On narrow screens the **CVE ID** column is hidden; the filters still work.
  - **Sorting:** Click **EPSS** in the column header. The first click sorts ascending, a second one shows the highest values first. Entries without EPSS come last in both directions.
  - **Filtering:** In the **Exploitation: all** filter, choose **Actively exploited**. In the **EPSS: all** filter, choose a minimum (**EPSS from 1 %**, **EPSS from 10 %**, or **EPSS from 50 %**). Both combine with the other filters.
- **Column widths:** From a screen width of 1200 pixels, drag the right edge of a column header to make the column wider or narrower. With the keyboard, focus the drag handle and press the Left or Right arrow key. OdinShield remembers the widths in this browser – even after you log out, and also for other accounts that sign in there. Once you have changed a width, the **Reset column widths** button appears next to the entries-per-page selector. If the table is wider than the screen, you can also scroll it sideways with the scrollbar directly above the table header.
- **Filter Links:** All filters are saved in the URL. Copy the browser URL to share a filtered view with colleagues.
- **Full-text search:** The search covers **Title**, **CVE ID**, your own **Description**, **Mitigation** and **Remediation Comment** — plus the imported advisory text. A product name or a phrase from the advisory takes you straight to the entry.
- **Search by number:** The **No.** column is the short reference for email and phone calls. Type the number with `#` into the search field, for example `#412`. The entry with that number is listed first. Without `#`, the search also finds entries whose text contains the digits, such as a CVE ID. Other active filters, such as status, still apply – reset them if the entry is missing. As with any search, only entries you are allowed to see appear. The same number is shown before the title in the detail view.

<details>
    <summary>Background: automatic CSAF imports</summary>
    <div>
        OdinShield pulls security advisories twice a day from the CSAF providers your administrator has registered (for example BSI, Siemens, SICK, Schneider Electric). A vulnerability is created automatically when vendor and product of a software in your inventory equal the advisory's <strong>and</strong> the deployed version lies inside the reported range. How the comparison works, and which imprecision it deliberately accepts, is described in <a href="inventar">Maintaining the inventory</a>.
        <br><br>
        Advisories that describe a package only <em>on a platform</em> (Red Hat's model: "openssl on RHEL 9") are currently not matched – there is no comparable version. In practice Red Hat therefore yields no hits.
    </div>
</details>

<details>
    <summary>Background: EPSS Score and "Actively exploited in the wild"</summary>
    <div>
        Once a day OdinShield enriches <strong>every vulnerability that carries a CVE ID and is not yet in a terminal status</strong> (status <strong>Open</strong>, <strong>In Progress</strong> or <strong>Deferred</strong>) – imported or entered by hand – with two figures from the original sources:
        <ul>
            <li><strong>EPSS Score:</strong> the probability (in percent) estimated by FIRST.org that the vulnerability is exploited within the next 30 days. When an entry bundles several CVEs, the highest value is shown.</li>
            <li><strong>Actively exploited in the wild:</strong> set as soon as one of the entry's CVEs is listed in the CISA KEV catalogue (known, actually exploited vulnerabilities). OdinShield never clears the flag by itself – not even when the CVE later drops off the list. How it doubles the effective risk and what happens when you untick it is described above under “Active exploitation doubles the risk”.</li>
        </ul>
        This enrichment creates <strong>no</strong> vulnerabilities and changes neither status nor severity. Entries in <strong>Resolved</strong>, <strong>Risk Accepted</strong> or <strong>Closed</strong> are no longer updated.
    </div>
</details>

## Bulk Action: Several Vulnerabilities at Once

> **Prerequisite:** Available to the **ISO** only (4-eyes principle), because the action can also close vulnerabilities.

In the list view you can set a status on several vulnerabilities together – for example to clean up quickly after a patch day or a CSAF import.

How to do it:

1. **Select:** Tick the desired rows in the list – or use the checkbox in the table header to select **all entries on the current page**. The selection is page-bound and resets when you filter, sort, or change pages.
2. **Action bar:** As soon as at least one row is selected, a bar with the count appears at the top. Use **Clear** to discard the selection, or **Set status** to open the dialog.
3. **Choose target status:** Choose the status for occurrences that aren't done yet (**Open**, **In Progress**, or **Deferred**). Each option also states the effect on the vulnerability itself, for example "Resolved – vulnerability will be closed". The dialog's opening sentence adapts to your choice. Optionally add a **comment**. OdinShield appends it to **Remediation Comment**, preceded by the date, your name, and your username, for example `[2026-10-24] Jane Doe (jdoe): …`.
4. **Apply:** The affected rows update in place. The result message reports three numbers: **updated** (successfully set), **skipped** (e.g. already closed) and **with conflict** (someone changed the entry in the meantime – these are left unchanged and can be processed again).

The chosen target status drives both levels:

| Target status of occurrences | Effect on the vulnerability |
|------------------------------|-----------------------------|
| **Resolved** | occurrences not yet done → resolved, vulnerability is **closed** (ISO sign-off) |
| **Risk Accepted** | occurrences not yet done → risk accepted, vulnerability is **closed** |
| **Deferred** | occurrences not yet done → deferred, vulnerability is set to **deferred** (not closed) |

Occurrences that are already done (**Resolved**, **Risk Accepted** or **Not Affected**) are left untouched; already-**closed** vulnerabilities are skipped.

## What's Next?

Prepare your data base to be able to precisely assign vulnerabilities:

<div class="manual-next-action">
    <div class="manual-next-action-label">Recommended Next Step</div>
    <h3 class="manual-next-action-title">Maintain Inventory & Assets</h3>
    <p class="manual-next-action-text">Learn how to create software and asset groups to build the foundation for risk calculation.</p>
    <a href="inventar" class="manual-next-action-button">
        To Chapter: Inventory
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
