<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Getting started

Welcome to OdinShield. This chapter is your launchpad: we'll guide you through the first 5 minutes so you can immediately start working productively with your security data.

OdinShield helps you systematically capture, evaluate, and track vulnerabilities. You always see **what to do**, **who is responsible**, and **how critical the risk** really is.

<div class="bento-grid">
    <a href="inventar" class="bento-card">
        <div class="bento-card-tag">Baseline Data</div>
        <div class="bento-card-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
        </div>
        <h3 class="bento-card-title">Inventory & Assets</h3>
        <p class="bento-card-text">Maintain software, asset groups, and vendors for a complete overview.</p>
    </a>
    <a href="schwachstellen" class="bento-card">
        <div class="bento-card-tag">Core Module</div>
        <div class="bento-card-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </div>
        <h3 class="bento-card-title">Vulnerabilities</h3>
        <p class="bento-card-text">Capture, assess, and successfully close security gaps.</p>
    </a>
    <a href="tipps" class="bento-card">
        <div class="bento-card-tag">Efficiency</div>
        <div class="bento-card-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
        </div>
        <h3 class="bento-card-title">Tips & Tricks</h3>
        <p class="bento-card-text">Work faster with filter links and proven routines.</p>
    </a>
</div>

## Your Quickstart Path

Follow these steps to get OdinShield up and running.

<div class="manual-stepper">
    <div class="manual-step">
        <div class="manual-step-number">1</div>
        <h3 class="manual-step-title">Sign in & Password</h3>
        <div class="manual-step-content">
            Sign in with your credentials. If your account is new, you will automatically be prompted to change your password.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">2</div>
        <h3 class="manual-step-title">Verify Inventory</h3>
        <div class="manual-step-content">
            Check under <strong>Inventory</strong> if your software products and asset groups are already listed. This is the foundation for every vulnerability.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">3</div>
        <h3 class="manual-step-title">Capture Vulnerability</h3>
        <div class="manual-step-content">
            Capture your first vulnerability. Assign a severity level and name a person responsible for the remediation.
        </div>
    </div>
</div>

## Key Features at a Glance

These three areas you will use daily:

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>The Dashboard</h4>
        <p>Your control center. Instantly see which critical vulnerabilities require your attention today.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Status Workflow</h4>
        <p>Every vulnerability moves through phases from <strong>Open</strong> to <strong>In Progress</strong> to <strong>Resolved</strong>.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Review Cycle</h4>
        <p>Software must be checked regularly. OdinShield reminds you when a review is overdue.</p>
    </div>
</div>

## Reading the Dashboard

Open the dashboard – the start page after login. Click the right-hand number on the **Open vulnerabilities** card: you land in the list filtered to your own entries. So that the numbers do not raise false expectations, here are the rules behind them:

* **Two numbers per card.** On the left the **Global** figure across all vulnerabilities, on the right **Assigned to you** – only entries where you are the responsible person. The **Assigned to me** filter in the list uses the same rule. The bell counter is narrower: it counts only your entries in status **Open**.
* **"Critical & High"** counts both severities. Clicking the card opens the list filtered to the same set – the number there matches the card.
* **The active set is counted:** Open, In Progress, Resolved and Deferred. Closed and risk-accepted vulnerabilities are not included.
* **"End of support approaching"** lists software whose end-of-life lies within the next **90 days** or has already passed.
* **"Review overdue"** lists manually maintained software not reviewed for more than **30 days** – with the **Mark reviewed** button right there (see [Inventory](inventar)).

The global figure and the monthly chart are visible to every signed-in person, even without a role or assignment. Anyone who then sees nothing in the list has no access rights to individual entries – the list explains that on the spot.

## Help in forms

Many form fields have a short help text. These help texts start collapsed so the form stays clear:

* **A single help text:** Select the info icon next to the field label (tooltip **Show help**). Select it again to hide the help text.
* **All help texts at once:** Most forms with several help texts have the **All help texts** switch at the top. It shows the help texts for every field; when you turn it off, it collapses them again. The setting applies to every form until you close the browser or turn the switch off.

## What's Next?

The first step is a clean database. Set up your inventory:

<div class="manual-next-action">
    <div class="manual-next-action-label">Recommended Next Step</div>
    <h3 class="manual-next-action-title">Maintain Inventory</h3>
    <p class="manual-next-action-text">Learn how to create software and asset groups to build the foundation for risk calculation.</p>
    <a href="inventar" class="manual-next-action-button">
        To Chapter: Inventory
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
