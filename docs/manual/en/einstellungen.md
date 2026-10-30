<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Personal Settings

These settings apply only to you. Here you can adapt OdinShield to your way of working without affecting other people's data or organization-wide configurations.

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>Profile & Language</h4>
        <p>Change your first and last name and choose between German and English.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Appearance</h4>
        <p>Switch between light and dark mode for a comfortable working experience.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Notifications</h4>
        <p>Control whether you want to be informed by email about new assignments.</p>
    </div>
</div>

## Where to find your settings?

Open the user menu (your avatar) and select **Settings**. There is no separate entry for it in the sidebar.

On that page you change **Email**, **First Name**, **Last Name**, **Locale**, **Appearance** and **Email notifications**. Name, locale, appearance and notifications are saved without your current password. For a new **Email**, you also enter your **Current password**.

After you save a new **Email**, OdinShield sends a security notice to your **previous** address. It states the time and the new address, partly masked (for example `an***@example.com`). The same happens when an administrator changes your address. If you did not make the change yourself, contact your administrator immediately. The notice is sent even if you have switched off **Email notifications**.

## Manage Password Securely

To set a new password on the **Settings** page:

1. In **Current password**, enter your current password.
2. In **Password**, enter the new password.
3. In **Confirm Password**, enter it again.
4. Select **Save Settings**.

The message "Your settings have been updated successfully." appears. Leave **Password** and **Confirm Password** empty and your current password stays as it is.

After a new password you receive a security notice at your email address – at the previous one if you changed the address in the same step. When an administrator resets your password, no notice is sent.

> **Note:** Every attempt to save a new password or a new email address counts, including successful ones. From the sixth attempt within one hour, you see "Too many attempts with the current password. Please try again in about … minutes." OdinShield then discards your other changes in that attempt too, even if they still appear in the form. Until the waiting time is over, even the correct password doesn't help. Password changes, email changes, and the password change required at your next sign-in share the same counter.

<details>
    <summary>Secure Password Requirements</summary>
    <div>
        A new password meets <strong>one</strong> of two variants that the German BSI names as equally good:
        <ul>
            <li><strong>Short and varied:</strong> at least 8 characters, including an uppercase letter, a lowercase letter, a digit and a punctuation character or space. Every character in this list counts as punctuation: <code>! " # $ % &amp; ' ( ) * + , - . / : ; &lt; = &gt; ? @ [ \ ] ^ _ ` { | } ~</code></li>
            <li><strong>Passphrase (our recommendation):</strong> at least 20 characters using at least two of the four character types (uppercase letters, lowercase letters, digits, punctuation and spaces) – for example several random words separated by spaces.</li>
        </ul>
        Characters such as € or § are allowed but count toward no requirement. Umlauts and ß count as letters; the BSI still advises against them because other keyboard layouts lack them.
        If a password meets neither variant, OdinShield lists what the short variant is still missing when you save. It also points to the passphrase as the alternative. OdinShield shows the same requirements as the help text for the <strong>Password</strong> field; in your settings, show it via the info icon next to the field label.
    </div>
</details>

> **Note:** After the first, mandatory password change you are signed out and sign in again with the new password.

## What's Next?

Ready to see OdinShield in action? Start with our onboarding guide:

<div class="manual-next-action">
    <div class="manual-next-action-label">Recommended Next Step</div>
    <h3 class="manual-next-action-title">Getting Started</h3>
    <p class="manual-next-action-text">Get to know the user interface and learn how to become productive in 5 minutes.</p>
    <a href="erste-schritte" class="manual-next-action-button">
        To Chapter: Getting Started
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
