<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Team & Responsibilities

This chapter is primarily intended for **Administrators** and **ISOs**. Learn how to configure OdinShield for your team and precisely control data access.

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>Standard User</h4>
        <p>The base role. Sees only vulnerabilities they are responsible for – directly, through their software, their asset group, or a user group.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Special Roles</h4>
        <p><strong>Software Owners</strong> maintain software and vendors, <strong>Hardware Owners</strong> asset groups and asset types. Both see all vulnerabilities – only Software Owners may edit them.</p>
    </div>
    <div class="manual-feature-card">
        <h4>ISO & Admin</h4>
        <p>The <strong>ISO</strong> has full access for approval; the <strong>Admin</strong> manages user accounts.</p>
    </div>
</div>

## Setting Up a New Team Member

Follow these steps to correctly configure a new account.

<div class="manual-stepper">
    <div class="manual-step">
        <div class="manual-step-number">1</div>
        <h3 class="manual-step-title">Create User Account</h3>
        <div class="manual-step-content">
            Create a new account under <strong>System → Users</strong>. Assign an initial password and select the <strong>Change password on next login</strong> checkbox.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">2</div>
        <h3 class="manual-step-title">Assign Roles</h3>
        <div class="manual-step-content">
            Select one or more roles. Each person automatically receives the base role "Standard User".
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">3</div>
        <h3 class="manual-step-title">User Groups (Optional)</h3>
        <div class="manual-step-content">
            Add the user to a <strong>user group</strong> to automatically give them access to specific asset types (e.g., "Server").
        </div>
    </div>
</div>

## Roles at a Glance

Each role extends the base role by a clearly delimited area. Rights in the inventory and rights on vulnerabilities are granted separately:

| Role | Inventory | See vulnerabilities | Edit vulnerabilities |
|------|-----------|---------------------|----------------------|
| **Standard User** | Software and asset groups for which they are the responsible person. | Own assignment, own software, own asset group or user group. | Own assignment, own software, user group – **not** via asset-group responsibility. |
| **Software Owner** | Creates and edits software and vendors. | All. | All. |
| **Hardware Owner** | Creates and edits asset groups and asset types. | All. | None. |
| **ISO** | Everything. | All. | All – the only role allowed to **close** and **delete** vulnerabilities. |
| **Administrator** | No rights of its own. | None in the vulnerability list – but their content through the audit timeline. | None. |

The administrator manages users, user groups and the audit timeline under **System** – the role brings no inventory or vulnerability rights; in the vulnerability list an administrator without a further role sees nothing. Roles combine freely. Someone who maintains software and signs off vulnerabilities gets Software Owner and ISO.

### The special position of the administrator

The administrator can give any account any role, including their own. They can therefore give themselves the ISO role, close or delete a vulnerability and remove the role again – bypassing the four-eyes principle. In addition, the audit timeline and its CSV export show the recorded content of every vulnerability, such as title, description, **Remediation Comment** and **Mitigation**, regardless of responsibilities.

This is intended: the administrator role is considered all-powerful. The only technical limit is that administrators cannot deactivate their own account or remove the administrator role from themselves. The role is controlled through the audit timeline: every change to roles is recorded there with person and time.

For operation we recommend:

- Give the administrator role to as few accounts as possible.
- Check the audit timeline regularly for changes to roles, especially roles an administrator gave themselves.

### What the administrator and the ISO see in addition

Some pages and notices exist only for these two roles:

- **Import Status** (administrator only): **System → Import Status** shows, per source of the automatic imports, when it last ran and last succeeded and which error occurred last. For every CSAF source the column **Documents (7 days)** shows how many advisories the feed listed in that time, how many were read and evaluated, and how many are still pending, failed or given up; given-up documents expand to their address and error. If a feed lists advisories but none of them was read, the source is marked **Reads nothing** – advisories are then being lost although every run counts as successful. The page is read-only; your system administration pauses or resumes sources with console commands.
- **Download SBOM** (administrator and ISO): the **Legal notices** page (link in the footer) offers the software bill of materials of this installation as a file. If there is none, a notice replaces the link.
- Warning about disrupted advisory sources (ISO and administrator): when a source is paused – usually automatically after repeated errors – the dashboard shows a warning. It states the number of disrupted sources and for how many days nothing has been retrieved successfully. It deliberately names neither the source nor the error text. Administrators find the details on the import status page; only their warning contains the link **Open import status**.
- Notice about a new OdinShield version (ISO and administrator): when a newer version is published, the dashboard shows a notice with the new and the installed version number, usually with the link **Release information**. If the release contains security fixes, it reads **Security update available** and appears as a warning instead of an information notice. Administrators also receive one email per new version. The notice cannot be dismissed; it disappears once the installation is updated. Pre-release versions are only reported to installations that run a pre-release themselves. For this, OdinShield checks a public version file once a day; the request contains no information about your installation. Your system administration can switch the check off on installations without internet access.
- Notice about objects without a responsible user (ISO only): if there are active vulnerabilities, software, or asset groups without a responsible user, the dashboard states how many and links the respective list. Once everything is assigned, the notice disappears.

## Working on Your Own

In a very small organisation one person often covers every task. OdinShield is built for that: assign **all roles** to your own account, at the very least **ISO**. Without it you can neither close nor delete vulnerabilities, and the bulk action in the list stays hidden.

The four-eyes principle then lapses organisationally: remediation and approval rest in the same hands. Keep the two steps apart anyway – first **Resolved** with an entry in **Remediation Comment** (OdinShield sets the date itself), then **Closed** in a second pass. The history will later show that a check took place rather than a box being ticked.

When a second person joins, give them the ISO role and drop it from your own account – the four-eyes principle is back in force without any further change.

## Vulnerability Visibility

OdinShield filters visibility automatically based on the "need-to-know" principle.

<details>
    <summary>Background: How visibility is calculated</summary>
    <div>
        A Standard User sees a vulnerability if:
        <ul>
            <li>They are directly entered as the responsible person.</li>
            <li>They are the responsible person for the affected software.</li>
            <li>They are the responsible person for an affected asset group (read-only, see below).</li>
            <li>They are a member of a user group that has access to the asset type of the affected asset group.</li>
        </ul>
        Special roles (ISO, Software Owner, Hardware Owner) always see all vulnerabilities. Hardware Owners see them <strong>read-only</strong>: status, responsible person and comments may be changed by ISOs, Software Owners and standard users who are assigned to the vulnerability directly, via the affected software or via a user group. Responsibility for an asset group only grants reading.
    </div>
</details>

## The Audit Timeline as Evidence

Every change to vulnerabilities, occurrences, software, asset groups, asset types, vendors, users and user groups is recorded with the person who made it and a timestamp. The history of a single object is on its detail page under the **History** tab. The history across all object types is available under **System → Audit Timeline**.

**Prerequisite:** The audit timeline and its export are reserved for the **administrator** role. As an ISO, use the **History** tab on the detail page of the object in question.

To evidence a change to an auditor:

<div class="manual-stepper">
    <div class="manual-step">
        <div class="manual-step-number">1</div>
        <h3 class="manual-step-title">Narrow it down</h3>
        <div class="manual-step-content">
            Open the <strong>audit timeline</strong> and combine the filters for action, object type, user and date range until only the change you are looking for remains.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">2</div>
        <h3 class="manual-step-title">Check the result</h3>
        <div class="manual-step-content">
            Rows that carry change details are marked with an arrow on the right. Click the arrow — or Tab to it and press Enter — to see the changed fields with their old and new values. Click the object name to jump to the object itself — as long as it still exists.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">3</div>
        <h3 class="manual-step-title">Export as CSV</h3>
        <div class="manual-step-content">
            Click <strong>CSV export</strong>. The file contains the <strong>whole filtered set, not just the page on screen</strong> — the page size has no effect on the export.
        </div>
    </div>
</div>

### What the CSV file contains

Every changed property gets its own row. The columns are: time, object type, object, object ID, action, field, old, new and changed by, so the file can be sorted and filtered in a spreadsheet. Entries without a field change — a plain link between two objects, for instance — appear with the field, old and new columns empty.

The file is semicolon-separated and UTF-8 encoded. In Excel set to a German or other European locale it opens straight into columns.

### Deleted objects

The history outlives the object. If an object has been deleted, the timeline shows its internal number (`#412`) followed by a **Deleted** marker; in the CSV file the same appears as `#412 (Deleted)` in the object column. The change stays evidenced even though the object is gone.

## What's Next?

Are all users set up? Then ensure your data base remains up to date:

<div class="manual-next-action">
    <div class="manual-next-action-label">Recommended Next Step</div>
    <h3 class="manual-next-action-title">Frequently Asked Questions (FAQ)</h3>
    <p class="manual-next-action-text">Find answers to typical questions about permissions and team organization.</p>
    <a href="faq" class="manual-next-action-button">
        To Chapter: FAQ
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
