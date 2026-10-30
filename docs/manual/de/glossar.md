<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Glossar

Begriffe in OdinShield kurz und in Klartext erklärt.

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>CVE</h4>
        <p><strong>Common Vulnerabilities and Exposures.</strong> Die weltweite ID für eine spezifische Sicherheitslücke.</p>
    </div>
    <div class="manual-feature-card">
        <h4>CPE</h4>
        <p><strong>Common Platform Enumeration.</strong> Die Produkt-ID, die viele Sicherheitsmeldungen tragen. OdinShield nutzt daraus nur Hersteller und Produkt.</p>
    </div>
    <div class="manual-feature-card">
        <h4>CVSS</h4>
        <p><strong>Common Vulnerability Scoring System.</strong> Der Standard-Punktwert (0-10) für die Schwere einer Lücke.</p>
    </div>
</div>

## Alle Begriffe von A bis Z

### Asset-Gruppe

Eine logische Gruppe von IT-Assets (z. B. "Webserver"). Hier werden Risiko-Eigenschaften wie Kritikalität und Exposition festgelegt.

### Audit-Trail

Die Änderungshistorie der protokollierten Objekte, jede Änderung mit Person und Zeitstempel – je Objekt im Reiter **Historie**, über alle Objekte hinweg in der Audit-Timeline (nur für Administratoren, siehe [Team & Zuständigkeiten](team)).

### BSI

Das Bundesamt für Sicherheit in der Informationstechnik. Eine zentrale Quelle für CSAF-Sicherheitsmeldungen in OdinShield.

### CISA KEV

Die "Known Exploited Vulnerabilities"-Liste der US-Behörde CISA — Schwachstellen, die nachweislich aktiv ausgenutzt werden. Das Kennzeichen **„Wird aktiv ausgenutzt"** spiegelt diese Liste; wie und wie oft es gesetzt wird, beschreibt [Schwachstellen verwalten](schwachstellen).

### CPE

**Common Platform Enumeration.** Die Produkt-ID in Sicherheitsmeldungen (`cpe:/o:fortinet:fortios:7.6.4`). Trägt eine Meldung eine CPE, vergleicht OdinShield nur deren Hersteller- und Produkt-Anteil mit Vendor-Key und Product-Key; ein eigenes CPE-Feld gibt es im Inventar nicht (mehr).

### CSAF

Das maschinenlesbare Format für Sicherheitswarnungen von Behörden und Herstellern. OdinShield nutzt CSAF für den automatischen Import.

### Effektives Risiko

Eine Zahl (1-480), die beschreibt, wie dringend eine Behebung wirklich ist. Es kombiniert die Schwere der Lücke mit der Wichtigkeit Ihrer Infrastruktur; ist die Lücke als *Wird aktiv ausgenutzt* gekennzeichnet, verdoppelt sich der Wert.

### End-of-Life (EOL)

Das Datum, an dem der Support für eine Software endet. OdinShield warnt rechtzeitig vor diesem Termin.

### EPSS

**Exploit Prediction Scoring System.** Ein von FIRST.org täglich berechneter Prozentwert, der schätzt, wie wahrscheinlich eine Schwachstelle in den nächsten 30 Tagen ausgenutzt wird. OdinShield ruft ihn einmal täglich direkt bei FIRST.org ab. Bündelt eine Schwachstelle mehrere CVEs, gilt der höchste Wert.

### ISB

Der Informationssicherheitsbeauftragte. Er hat Vollzugriff und schließt Schwachstellen nach dem Vier-Augen-Prinzip ab.

### NVD

Die National Vulnerability Database. Die primäre Quelle für CVSS-Bewertungen weltweit. Ihre CPE Search hilft, Vendor-Key und Product-Key richtig zu schreiben – siehe [Inventar pflegen](inventar).

### OT

**Operational Technology** – Steuerungen, Sensorik und andere Technik, die physische Prozesse steuert. OdinShield führt OT im selben Inventar wie IT, ohne eigenes Modell. Wie Sie ein OT-Gerät abbilden, zeigt das Kapitel [Inventar pflegen](inventar).

### Status

Schwachstellen und Vorkommen haben jeweils einen **eigenen** Status. Der Vorkommen-Status beschreibt die Lage je Asset-Gruppe, der Schwachstellen-Status den Gesamtbefund. Mehr dazu im Kapitel [Schwachstellen verwalten](schwachstellen).

### Vorkommen

Die konkrete Verknüpfung einer Schwachstelle mit einer Asset-Gruppe. Eine Schwachstelle kann viele Vorkommen haben – jedes mit eigenem Status (z. B. eines **behoben**, ein anderes **Risiko akzeptiert**).

## Wie geht's weiter?

Bereit für den Start? Kehren Sie zum Anfang zurück, um Ihre ersten Schritte in OdinShield zu machen:

<div class="manual-next-action">
    <div class="manual-next-action-label">Empfohlener nächster Schritt</div>
    <h3 class="manual-next-action-title">Zurück zum Anfang</h3>
    <p class="manual-next-action-text">Starten Sie mit dem Onboarding-Guide und richten Sie Ihr Team und Inventar ein.</p>
    <a href="erste-schritte" class="manual-next-action-button">
        Zum Kapitel: Erste Schritte
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
