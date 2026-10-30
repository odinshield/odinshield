<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Häufige Fragen (FAQ)

Antworten auf Fragen, die in der täglichen Arbeit mit OdinShield immer wieder auftauchen.

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>Schwachstellen</h4>
        <p>Fragen zu Sichtbarkeit, Status-Workflow und Risiko-Bewertung.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Inventar</h4>
        <p>Fragen zu Software-Anlage, Reviews und EOL-Daten.</p>
    </div>
    <div class="manual-feature-card">
        <h4>CSAF-Importe</h4>
        <p>Fragen zur Automatisierung und zum Abgleich.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Konto & Team</h4>
        <p>Fragen zu Login, Berechtigungen und Rollen.</p>
    </div>
</div>

## Schwachstellen & Workflow

<details>
    <summary>Warum sehe ich diese Schwachstelle plötzlich nicht mehr?</summary>
    <div>
        Mögliche Gründe:
        <ul>
            <li>Sie sind nicht mehr verantwortlich (jemand anderes wurde zugewiesen).</li>
            <li>Sie wurden aus einer Nutzergruppe entfernt.</li>
            <li>Die Schwachstelle wurde <strong>geschlossen</strong>, und Ihre Liste filtert auf <strong>Aktiv</strong> – etwa weil Sie über eine Dashboard-Karte gekommen sind. Ohne gesetzten Filter zeigt die Liste alle Status. Wählen Sie im Statusfilter <strong>Alle Status</strong>, <strong>Inaktiv</strong> oder <strong>Geschlossen</strong>.</li>
        </ul>
    </div>
</details>

<details>
    <summary>Warum kann ich eine Schwachstelle nicht schließen?</summary>
    <div>
        Das endgültige Schließen ist dem <strong>ISB</strong> vorbehalten (Vier-Augen-Prinzip). Setzen Sie den Status auf <strong>Behoben</strong> und bitten Sie Ihren ISB um Abnahme.
    </div>
</details>

<details>
    <summary>Warum kann ich eine Schwachstelle nicht löschen?</summary>
    <div>
        Löschen ist wie das Schließen dem <strong>ISB</strong> vorbehalten. Trifft ein Befund auf eine Asset-Gruppe nicht zu, setzen Sie das Vorkommen auf <strong>Nicht betroffen</strong>, statt die Gruppe zu entfernen (siehe <a href="schwachstellen">Schwachstellen verwalten</a>). Einen echten Fehleintrag lässt der ISB löschen.
    </div>
</details>

<details>
    <summary>Der Import meldet „Sicherheitspatch verfügbar" – warum steht die Schwachstelle noch auf Offen?</summary>
    <div>
        Weil der Import den Status nie selbst ändert. Er hinterlässt den Hinweis und das Kennzeichen, die Bestätigung, dass der Patch eingespielt ist, bleibt bei Ihnen. Setzen Sie das Vorkommen auf <strong>Behoben</strong>, sobald das Update aktiv ist.
    </div>
</details>

<details>
    <summary>Muss ich jedes Vorkommen einzeln bearbeiten?</summary>
    <div>
        Im Normalfall ja: Jedes Vorkommen ist eine eigene Instanz in einer Asset-Gruppe. Die Behebung kann pro Gruppe unterschiedlich aussehen (z. B. Patch vs. Konfigurationsänderung).
        Als <strong>ISB</strong> setzen Sie alle noch nicht erledigten Vorkommen einer oder mehrerer Schwachstellen in einem Schritt auf denselben Status. Nutzen Sie dazu die Sammelaktion <strong>Status setzen</strong> in der Schwachstellenliste. Zur Wahl stehen <strong>Behoben</strong>, <strong>Risiko akzeptiert</strong> und <strong>Zurückgestellt</strong>; die Schwachstelle wird damit geschlossen bzw. zurückgestellt (siehe <a href="schwachstellen#sammelaktion-mehrere-schwachstellen-auf-einmal">Sammelaktion</a>).
    </div>
</details>

## Inventar & Software

<details>
    <summary>Warum kann ich keine neue Software anlegen?</summary>
    <div>
        Die Anlage von Software ist Software-Ownern und ISBs vorbehalten. Als Verantwortlicher einer bestehenden Software dürfen Sie diese jedoch bearbeiten.
    </div>
</details>

<details>
    <summary>Was passiert beim Anlegen einer Nachfolgerversion?</summary>
    <div>
        OdinShield fragt Sie pro Asset-Gruppe, ob diese auf der alten Version bleibt oder zur neuen migriert wird. So entsteht eine lückenlose Historie.
    </div>
</details>

## Automatisierung (CSAF)

<details>
    <summary>Warum erhalte ich keine automatischen Schwachstellen?</summary>
    <div>
        Prüfen Sie diese fünf Punkte:
        <ul>
            <li>Die <strong>Pflege-Quelle</strong> der Software steht auf <strong>Import</strong>, Software und Hersteller sind <strong>Aktiv</strong>.</li>
            <li>Die Software ist mindestens einer <strong>Asset-Gruppe</strong> zugeordnet.</li>
            <li><strong>Vendor-Key</strong> und <strong>Product-Key</strong> lauten so, wie Advisories das Produkt nennen – sonst hilft ein Alias-Name.</li>
            <li>Die Meldung ist mindestens mit <strong>Mittel</strong> bewertet – niedriger bewertete Meldungen werden nicht importiert.</li>
            <li>Die Meldung ist <strong>nach</strong> dem Anlegen oder Korrigieren der Software erschienen oder aktualisiert worden. Ältere Meldungen prüft der Import nicht von selbst erneut – Ihre Administration kann sie mit <code>odinshield:csaf:rewind</code> nachholen.</li>
        </ul>
        Eine Version ohne führende Zahl ist nie die Ursache: sie trifft jede Meldung. Wie der Abgleich arbeitet, beschreibt das Kapitel <a href="inventar">Inventar pflegen</a>.
    </div>
</details>

<details>
    <summary>Muss ich noch CPE-IDs pflegen?</summary>
    <div>
        Nein. Das Feld CPE gibt es seit Version 2.2.0 nicht mehr. Trägt eine Meldung eine CPE-ID, nutzt OdinShield daraus nur den Hersteller- und Produkt-Anteil und vergleicht ihn mit Ihren Keys.
    </div>
</details>

<details>
    <summary>Warum wurde eine Schwachstelle gemeldet, obwohl mein Patch-Stand schon neuer ist?</summary>
    <div>
        Weil das Inventar die Version meist nur auf Major/Minor-Ebene führt („7.6"), das Advisory die Grenze aber auf Patch-Ebene nennt („unterhalb 7.6.4"). OdinShield meldet in diesem Fall bewusst – lieber einmal zu viel. Im Reiter <strong>Advisory</strong> endet der Text unter <strong>Beschreibung aus der Quelle</strong> mit dem Abschnitt <code>## Matched because</code> (englisch), der den verglichenen Bereich nennt. Liegt Ihr Patch-Stand darüber, setzen Sie das Vorkommen auf <strong>Nicht betroffen</strong> oder pflegen Sie die Version mit Patch-Stand – siehe <a href="inventar">Inventar pflegen</a>.
    </div>
</details>

<details>
    <summary>Woher kommen EPSS-Score und „Wird aktiv ausgenutzt"?</summary>
    <div>
        Aus den Originalquellen FIRST.org (EPSS) und CISA KEV, einmal täglich für jede noch nicht abgeschlossene Schwachstelle mit CVE-ID – auch für von Hand erfasste.
    </div>
</details>

## Wie geht's weiter?

Sollte ein Fachbegriff unklar sein, hilft Ihnen unser Lexikon weiter:

<div class="manual-next-action">
    <div class="manual-next-action-label">Empfohlener nächster Schritt</div>
    <h3 class="manual-next-action-title">Glossar & Fachbegriffe</h3>
    <p class="manual-next-action-text">Schlagen Sie technische Begriffe wie CVE, EPSS oder effektives Risiko in unserem Glossar nach.</p>
    <a href="glossar" class="manual-next-action-button">
        Zum Kapitel: Glossar
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
