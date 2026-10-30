<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Erste Schritte

Willkommen bei OdinShield. Dieses Kapitel ist Ihre Startrampe: Wir führen Sie durch die ersten 5 Minuten, damit Sie sofort produktiv mit Ihren Sicherheitsdaten arbeiten können.

OdinShield hilft Ihnen, Schwachstellen systematisch zu erfassen, zu bewerten und nachzuverfolgen. Sie sehen jederzeit, **was zu tun ist**, **wer zuständig ist** und **wie kritisch das Risiko** wirklich ist.

<div class="bento-grid">
    <a href="inventar" class="bento-card">
        <div class="bento-card-tag">Basis-Daten</div>
        <div class="bento-card-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
        </div>
        <h3 class="bento-card-title">Inventar & Assets</h3>
        <p class="bento-card-text">Software, Asset-Gruppen und Hersteller für die Übersicht pflegen.</p>
    </a>
    <a href="schwachstellen" class="bento-card">
        <div class="bento-card-tag">Zentrales Modul</div>
        <div class="bento-card-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </div>
        <h3 class="bento-card-title">Schwachstellen</h3>
        <p class="bento-card-text">Sicherheitslücken erfassen, bewerten und erfolgreich schließen.</p>
    </a>
    <a href="tipps" class="bento-card">
        <div class="bento-card-tag">Effizienz</div>
        <div class="bento-card-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
        </div>
        <h3 class="bento-card-title">Tipps & Tricks</h3>
        <p class="bento-card-text">Schneller arbeiten mit Filter-Links und bewährten Routinen.</p>
    </a>
</div>

## Ihr Schnellstart-Pfad

Folgen Sie diesen Schritten, um OdinShield in Betrieb zu nehmen.

<div class="manual-stepper">
    <div class="manual-step">
        <div class="manual-step-number">1</div>
        <h3 class="manual-step-title">Anmelden & Passwort</h3>
        <div class="manual-step-content">
            Melden Sie sich mit Ihren Zugangsdaten an. Falls Ihr Account neu ist, werden Sie automatisch zur Passwort-Änderung aufgefordert.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">2</div>
        <h3 class="manual-step-title">Inventar prüfen</h3>
        <div class="manual-step-content">
            Prüfen Sie unter <strong>Inventar</strong>, ob Ihre Softwareprodukte und Asset-Gruppen bereits hinterlegt sind. Dies ist die Basis für jede Schwachstelle.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">3</div>
        <h3 class="manual-step-title">Schwachstelle erfassen</h3>
        <div class="manual-step-content">
            Erfassen Sie Ihre erste Schwachstelle. Weisen Sie einen Schweregrad zu und benennen Sie einen Verantwortlichen für die Behebung.
        </div>
    </div>
</div>

## Kernfunktionen im Überblick

Diese drei Bereiche werden Sie täglich nutzen:

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>Das Dashboard</h4>
        <p>Ihre Zentrale. Hier sehen Sie sofort, welche kritischen Schwachstellen heute Ihre Aufmerksamkeit benötigen.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Status-Workflow</h4>
        <p>Jede Schwachstelle durchläuft Phasen von <strong>Offen</strong> über <strong>In Bearbeitung</strong> bis zu <strong>Behoben</strong>.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Review-Zyklus</h4>
        <p>Software muss regelmäßig geprüft werden. OdinShield erinnert Sie, wenn ein Review überfällig ist.</p>
    </div>
</div>

## Das Dashboard lesen

Öffnen Sie das Dashboard – die Startseite nach dem Login. Klicken Sie auf die rechte Zahl der Karte **Offene Schwachstellen**: Sie landen in der Liste, gefiltert auf Ihre eigenen Einträge. Damit die Zahlen keine falschen Erwartungen wecken, hier die Regeln dahinter:

* **Zwei Zahlen je Karte.** Links **Global** über alle Schwachstellen, rechts **Dir zugewiesen** – nur Einträge, bei denen Sie selbst als Verantwortlicher eingetragen sind. Dieselbe Abgrenzung nutzt der Filter **Mir zugewiesen** in der Liste. Der Zähler an der Glocke ist enger: Er zählt nur Ihre Einträge im Status **Offen**.
* **„Kritisch & Hoch"** zählt beide Schweregrade. Ein Klick auf die Karte führt in die Liste, gefiltert auf denselben Satz – die Zahl dort stimmt mit der Karte überein.
* **Gezählt wird der aktive Bestand:** Offen, In Bearbeitung, Behoben und Zurückgestellt. Geschlossene und als Risiko akzeptierte Schwachstellen sind nicht enthalten.
* **„Support-Ende nähert sich"** listet Software, deren End-of-Life innerhalb der nächsten **90 Tage** liegt oder bereits überschritten ist.
* **„Prüfung überfällig"** listet manuell gepflegte Software, die seit mehr als **30 Tagen** nicht geprüft wurde – direkt mit der Schaltfläche **Als geprüft markieren** (siehe [Inventar](inventar)).

Die globale Zahl und das Monatsdiagramm sehen alle angemeldeten Personen, auch ohne Rolle oder Zuweisung. Wer in der Liste dann nichts sieht, hat keine Zugriffsrechte auf einzelne Einträge – die Liste erklärt das an Ort und Stelle.

## Hilfe in Formularen

Viele Formularfelder haben einen kurzen Hilfetext. Diese Hilfetexte sind zunächst eingeklappt, damit das Formular übersichtlich bleibt:

* **Einzelner Hilfetext:** Klicken Sie auf das Info-Symbol neben der Feldbeschriftung (Tooltip **Hilfe einblenden**). Ein zweiter Klick blendet den Hilfetext wieder aus.
* **Alle Hilfetexte auf einmal:** Die meisten Formulare mit mehreren Hilfetexten haben oben den Schalter **Alle Hilfetexte**. Er blendet die Hilfetexte zu allen Feldern ein; ausgeschaltet klappt er sie wieder ein. Die Einstellung gilt für alle Formulare, bis Sie den Browser schließen oder den Schalter wieder ausschalten.

## Wie geht's weiter?

Der erste Schritt ist eine saubere Datenbasis. Legen Sie Ihr Inventar an:

<div class="manual-next-action">
    <div class="manual-next-action-label">Empfohlener nächster Schritt</div>
    <h3 class="manual-next-action-title">Inventar pflegen</h3>
    <p class="manual-next-action-text">Erfahren Sie, wie Sie Software und Asset-Gruppen anlegen, um die Grundlage für die Risikoberechnung zu schaffen.</p>
    <a href="inventar" class="manual-next-action-button">
        Zum Kapitel: Inventar
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
