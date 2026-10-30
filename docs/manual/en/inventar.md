<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Maintain inventory

Inventory is the foundation of OdinShield. Without cleanly maintained software and asset groups, vulnerabilities remain abstract — with them, they become tangible and assessable.

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>Software</h4>
        <p>Capture your applications and operating systems including version and support status.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Asset Groups</h4>
        <p>Bundle your IT infrastructure and define the criticality for your business.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Vendors</h4>
        <p>Manage providers centrally to keep your software data consistent.</p>
    </div>
</div>

## Building Your Inventory

Follow this sequence to create a solid data base.

<div class="manual-stepper">
    <div class="manual-step">
        <div class="manual-step-number">1</div>
        <h3 class="manual-step-title">Vendors & Software</h3>
        <div class="manual-step-content">
            Create your software products. Important: maintain <strong>Vendor Key</strong>, <strong>Product Key</strong> and <strong>Version</strong> the way security advisories name the product – that is how OdinShield assigns CSAF advisories automatically (section "Maintain keys and versions so advisories match").
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">2</div>
        <h3 class="manual-step-title">Define Asset Groups</h3>
        <div class="manual-step-content">
            Group your assets (e.g., "Webserver Production"). Define how <strong>exposed</strong> (Internet vs. Internal) and how <strong>critical</strong> this group is.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">3</div>
        <h3 class="manual-step-title">Linking</h3>
        <div class="manual-step-content">
            Once software is assigned to an asset group, OdinShield is ready to monitor vulnerabilities for exactly that combination.
        </div>
    </div>
</div>

> **This assignment is the only place it is maintained.** The **Software** field on the asset group determines which asset groups are offered at all when you record a vulnerability for that product. If an entry is missing there, the vulnerability cannot be created for that group – maintain the assignment here first. Conversely: the vulnerability form never creates an inventory link.

## Maintaining Responsibilities

Use the assignment of <strong>responsible persons</strong> to ensure your data is properly maintained:

* **Software:** The responsible person takes care of version updates and the accuracy of keys, alias names and version.
* **Asset Group:** Who knows this infrastructure best technically? This person gains visibility for related vulnerabilities.

## The Review Cycle

OdinShield helps you keep your inventory up to date. A software's **maintenance source** defines how its vulnerabilities are maintained: **Import** (automatically via the CSAF import), **Manual** (by you), or **None**. Software with the maintenance source **Manual** must be checked regularly.

* **Dashboard Alert:** Software with the maintenance source **Manual** that hasn't been checked for more than 30 days appears as "overdue."
* **Record a review:** Once you have checked a manually maintained software against the vendor's advisories, click **Mark reviewed** – in the row menu of the software list or directly in the dashboard widget "Review overdue". This sets "Last reviewed" to today. The button is shown to ISOs, Software Owners and the software's responsible person.
* **Automation:** Set the maintenance source to **Import** — the CSAF import then checks the software against every advisory. Every successful import run counts as a review, and such software never appears as overdue.

## Maintain keys and versions so advisories match

The CSAF import compares three inventory values with every security advisory: the vendor's **Vendor Key**, the software's **Product Key** and the **Version**. An advisory hits a software when vendor and product are equal **and** the deployed version lies inside the reported range. Prerequisites: maintenance source **Import**, software and vendor **Active**, at least one assigned asset group. Without an asset group the import still hits the software but creates no vulnerability; the software list then shows a warning icon after the name and the detail view the note **No asset group assigned, the import creates no vulnerabilities**.

* **Write the Vendor Key and Product Key the way advisories name the product:** `fortinet` / `fortios`, `siemens` / `simatic_s7_1500`, `opnsense` / `opnsense`. Case does not matter; spaces and punctuation both become `_`. "FortiOS" and `fortios` are equal, but "SICK AG" becomes `sick_ag` and does **not** equal `sick`. When an advisory carries a CPE ID, OdinShield uses its vendor and product part; otherwise the names from the advisory.
* **Look up the spelling in the NVD CPE Search:** the [NVD CPE Search](https://nvd.nist.gov/products/cpe/search) shows the CPE ID a product is listed under; the software and vendor forms link to it below the key field. Take only two parts of the CPE ID: `cpe:2.3:o:fortinet:fortios:7.6.3:…` gives the Vendor Key `fortinet` and the Product Key `fortios`. There is no field for the whole CPE ID; you maintain the version separately.
* **Add an alias name where the spelling differs:** on the vendor, for example "SICK AG" for the key `sick`; on the software "CorivaEngine" for `coriva_engine`. An alias counts like the key. Similar but not equal names produce **no** hit. The import only records them in the application log as alias candidates – ask your administrator for it, or add the alias right away.
* **Record the version with a leading numeric part:** only that part is compared. "7.6.3 build 1234" counts as 7.6.3, "2024 R2" as 2024; the detail view shows this as **compared as 7.6.3**. Major/minor is enough: a software with version "7.6" counts as every 7.6.x. Without a leading number ("latest"), software with the maintenance source **Import** shows a warning icon in the software list and the note **Version not comparable, matches unfiltered** in its detail view – every advisory for that product is then imported.

> **Deliberate imprecision:** When an advisory reports "affected below 7.6.4" and your inventory says "7.6", a vulnerability is created – even if you actually run 7.6.5. OdinShield would rather report once too often than once too rarely. In the **Advisory** tab of the vulnerability, the text under **Description from the source** ends with a section `## Matched because` (English, written by the import); it names the compared range and your inventory version. If your patch level is already past the bound, set the occurrence to **Not Affected** – or record the version with its patch level, then the advisory stays away in future.

**When the import checks:** Every advisory is checked when it is published or updated – against the inventory as it stands at that moment. When you add a software or correct its keys, version or asset group, that takes effect for all advisories from the next import run on. OdinShield does not check older advisories again on its own. If a new software should also be checked against the advisories of the past weeks, ask your administration to have the provider's advisories read again: the console command `odinshield:csaf:rewind bsi_wid --days=90` makes the next runs read the BSI advisories of the last 90 days once more; large amounts are spread over several runs. Advisories already recorded stay unchanged.

<details>
    <summary>Not backwards compatible: the CPE field is gone (version 2.2.0)</summary>
    <div>
        <strong>For administrators, once after the update to 2.2.0.</strong> Up to version 2.1 every software carried a <strong>CPE</strong> field that drove the import. That field no longer exists; matching runs on keys, alias names and version only. During the update OdinShield checks every former CPE against the keys and reports differences in the update log – <strong>nothing is corrected automatically</strong>. After the update, check that Vendor Key and Product Key read the way advisories name the product, and add alias names where the spelling differs. The console command <code>odinshield:vulnerability:recheck</code> re-evaluates every open imported vulnerability and reports for each one whether it would still match – it changes nothing.
    </div>
</details>

## Modelling OT Devices

Controllers, sensors and other OT components need no model of their own – the inventory covers them with the same three building blocks as servers and applications. Using a PLC on the shop floor as the example:

<div class="manual-stepper">
    <div class="manual-step">
        <div class="manual-step-number">1</div>
        <h3 class="manual-step-title">Asset type "Controller"</h3>
        <div class="manual-step-content">
            Create the type "Controller" under <strong>Inventory → Asset Types</strong> (Hardware Owner or ISO). The asset type is also what later decides which user group sees these devices.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">2</div>
        <h3 class="manual-step-title">Asset group "PLC Hall 3"</h3>
        <div class="manual-step-content">
            Asset type Controller, business criticality <strong>High</strong> (production standstill), network exposure <strong>Isolated</strong> (dedicated production network without internet access), data sensitivity as you classify it.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">3</div>
        <h3 class="manual-step-title">Firmware as software</h3>
        <div class="manual-step-content">
            Vendor "Siemens", name "SIMATIC S7-1500 Firmware", version "2.9". The firmware level is the version – there is no separate firmware management, and none is needed. Assign the software to the asset group.
        </div>
    </div>
</div>

> **Note:** The **Isolated** exposure lowers the effective risk of every vulnerability on this group – that is how a segregated network shows up in the rating.

**Cannot patch?** A controller often cannot be updated at short notice: vendor approval, acceptance testing, downtime. Then document how you handle it instead of the patch:

* Enter the protective measure in the vulnerability's **Mitigation** field – network segmentation, a firewall rule, access only via the maintenance VPN. On vulnerabilities from the **CSAF import**, every new advisory version prepends a block of its own to this field; your entry moves down but is kept.
* Set the occurrence on this asset group to **Risk Accepted** and give the reason in the occurrence's note field.
* If the flaw only affects a function that is switched off on your devices, **Not Affected** is the right status.

The finding stays visible and provable without filling the list with unsolvable tasks. If the update does arrive later, set the occurrence to **Resolved**. The individual status values are explained in [Manage vulnerabilities](schwachstellen).

## What's Next?

Once your inventory is set up, you should define who has access to which data:

<div class="manual-next-action">
    <div class="manual-next-action-label">Recommended Next Step</div>
    <h3 class="manual-next-action-title">Organize Team & Roles</h3>
    <p class="manual-next-action-text">Learn how to create user groups and control access to your asset groups.</p>
    <a href="team" class="manual-next-action-button">
        To Chapter: Team & Roles
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
