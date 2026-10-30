<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Team & Zuständigkeiten

Dieses Kapitel richtet sich primär an **Administratoren** und **ISBs**. Erfahren Sie, wie Sie OdinShield für Ihr Team konfigurieren und den Zugriff auf Daten präzise steuern.

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>Standard-Nutzer</h4>
        <p>Die Basis-Rolle. Sieht nur Schwachstellen, für die er zuständig ist – direkt, über seine Software, seine Asset-Gruppe oder eine Nutzergruppe.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Spezial-Rollen</h4>
        <p><strong>Software-Owner</strong> pflegen Software und Hersteller, <strong>Hardware-Owner</strong> Asset-Gruppen und Asset-Typen. Beide sehen alle Schwachstellen – bearbeiten dürfen sie nur Software-Owner.</p>
    </div>
    <div class="manual-feature-card">
        <h4>ISB & Admin</h4>
        <p>Der <strong>ISB</strong> hat Vollzugriff zur Abnahme; der <strong>Admin</strong> verwaltet Nutzerkonten.</p>
    </div>
</div>

## Ein neues Teammitglied einrichten

Folgen Sie diesen Schritten, um ein neues Konto korrekt zu konfigurieren.

<div class="manual-stepper">
    <div class="manual-step">
        <div class="manual-step-number">1</div>
        <h3 class="manual-step-title">Nutzerkonto anlegen</h3>
        <div class="manual-step-content">
            Erstellen Sie unter <strong>Systemverwaltung → Nutzer</strong> ein neues Konto. Vergeben Sie ein Initialpasswort und aktivieren Sie die Option <strong>Passwort bei nächstem Login ändern</strong>.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">2</div>
        <h3 class="manual-step-title">Rollen zuweisen</h3>
        <div class="manual-step-content">
            Wählen Sie eine oder mehrere Rollen aus. Jede Person erhält automatisch die Basis-Rolle "Standard-Nutzer".
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">3</div>
        <h3 class="manual-step-title">Nutzergruppen (Optional)</h3>
        <div class="manual-step-content">
            Fügen Sie den Nutzer einer <strong>Nutzergruppe</strong> hinzu, um ihm automatisch Zugriff auf bestimmte Asset-Typen (z. B. "Server") zu geben.
        </div>
    </div>
</div>

## Rollen im Überblick

Jede Rolle erweitert die Basis-Rolle um einen klar umrissenen Bereich. Rechte im Inventar und Rechte an Schwachstellen sind dabei getrennt vergeben:

| Rolle | Inventar | Schwachstellen sehen | Schwachstellen bearbeiten |
|-------|----------|----------------------|---------------------------|
| **Standard-Nutzer** | Software und Asset-Gruppen, für die er als Verantwortlicher eingetragen ist. | Eigene Zuweisung, eigene Software, eigene Asset-Gruppe oder Nutzergruppe. | Eigene Zuweisung, eigene Software, Nutzergruppe – **nicht** über die Asset-Gruppen-Verantwortung. |
| **Software-Owner** | Legt Software und Hersteller an und bearbeitet sie. | Alle. | Alle. |
| **Hardware-Owner** | Legt Asset-Gruppen und Asset-Typen an und bearbeitet sie. | Alle. | Nichts. |
| **ISB** | Alles. | Alle. | Alle – allein berechtigt, Schwachstellen zu **schließen** und zu **löschen**. |
| **Administrator** | Keine eigenen Rechte. | Nichts in der Schwachstellenliste – die Inhalte aber über die Audit-Timeline. | Nichts. |

Der Administrator verwaltet Nutzer, Nutzergruppen und die Audit-Timeline unter **Systemverwaltung** – Inventar- oder Schwachstellenrechte bringt die Rolle nicht mit; in der Schwachstellenliste sieht ein Administrator ohne weitere Rolle nichts. Die Rollen lassen sich frei kombinieren. Wer Software pflegt und Schwachstellen abnimmt, bekommt Software-Owner und ISB.

### Die Sonderstellung des Administrators

Der Administrator kann jedem Konto jede Rolle geben, auch sich selbst. Damit kann er sich die Rolle ISB geben, eine Schwachstelle schließen oder löschen und die Rolle wieder entfernen – am Vier-Augen-Prinzip vorbei. Außerdem zeigen die Audit-Timeline und ihr CSV-Export die protokollierten Inhalte aller Schwachstellen, etwa Titel, Beschreibung, **Kommentar zur Behebung** und **Minderung**, unabhängig von Zuständigkeiten.

Das ist gewollt: Die Rolle Administrator gilt als allmächtig. Technisch verhindert OdinShield nur, dass ein Administrator sich selbst deaktiviert oder sich selbst die Rolle Administrator nimmt. Kontrolliert wird die Rolle über die Audit-Timeline: Jede Änderung an Rollen steht dort mit Person und Zeitpunkt.

Für den Betrieb empfehlen wir:

- Vergeben Sie die Rolle Administrator an so wenige Konten wie möglich.
- Prüfen Sie die Audit-Timeline regelmäßig auf Änderungen an Rollen, besonders auf Rollen, die ein Administrator sich selbst gegeben hat.

### Was Administrator und ISB zusätzlich sehen

Einige Seiten und Hinweise gibt es nur für diese beiden Rollen:

- **Importstatus** (nur Administrator): Unter **Systemverwaltung → Importstatus** sehen Sie je Quelle der automatischen Importe, wann sie zuletzt lief und erfolgreich war und welcher Fehler zuletzt auftrat. Für jede CSAF-Quelle zeigt die Spalte **Dokumente (7 Tage)**, wie viele Meldungen der Feed in dieser Zeit gelistet hat, wie viele gelesen und ausgewertet wurden und wie viele noch offen, fehlgeschlagen oder aufgegeben sind; aufgegebene Dokumente lassen sich mit Adresse und Fehler aufklappen. Listet ein Feed Meldungen, ohne dass eine davon gelesen wurde, steht bei der Quelle **Liest nichts** – dann gehen Meldungen verloren, obwohl jeder Lauf als erfolgreich gilt. Die Seite ist nur zum Lesen; Quellen pausiert oder setzt Ihre Systemverwaltung über Konsolenbefehle fort.
- **SBOM herunterladen** (Administrator und ISB): Die Seite **Rechtliches** (Link in der Fußzeile) bietet die Software-Stückliste dieser Installation als Datei an. Fehlt sie, steht dort ein Hinweis statt des Links.
- Warnhinweis zu gestörten Advisory-Quellen (ISB und Administrator): Ist eine Quelle pausiert – meist automatisch nach wiederholten Fehlern –, zeigt das Dashboard einen Warnhinweis. Er nennt die Zahl der gestörten Quellen und seit wie vielen Tagen nichts mehr erfolgreich abgerufen wurde. Name und Fehlertext der Quelle nennt er bewusst nicht. Administratoren finden die Details im Importstatus; nur bei ihnen enthält der Warnhinweis den Link **Importstatus öffnen**.
- Hinweis auf eine neue OdinShield-Version (ISB und Administrator): Ist eine neuere Version veröffentlicht, zeigt das Dashboard einen Hinweis mit der neuen und der installierten Versionsnummer, meist mit dem Link **Release-Informationen**. Enthält das Release Sicherheitskorrekturen, steht dort **Sicherheitsupdate verfügbar** und der Hinweis erscheint als Warnung statt als Information. Administratoren erhalten zusätzlich einmal je neuer Version eine E-Mail. Der Hinweis lässt sich nicht wegklicken; er verschwindet, sobald die Installation aktualisiert ist. Vorabversionen meldet OdinShield nur Installationen, die selbst eine Vorabversion verwenden. OdinShield prüft dafür einmal täglich eine öffentliche Versionsdatei; die Anfrage enthält keine Angaben über Ihre Installation. Installationen ohne Internetzugang kann Ihre Systemverwaltung von der Prüfung ausnehmen.
- Hinweis auf Objekte ohne Verantwortlichen (nur ISB): Gibt es aktive Schwachstellen, Software oder Asset-Gruppen ohne eingetragenen Verantwortlichen, nennt das Dashboard ihre Zahl und verlinkt die jeweilige Liste. Ist alles zugeordnet, verschwindet der Hinweis.

## Wenn Sie allein arbeiten

In einem Kleinstbetrieb übernimmt oft eine Person alle Aufgaben. OdinShield ist darauf ausgelegt: Weisen Sie Ihrem eigenen Konto **alle Rollen** zu, mindestens aber **ISB**. Ohne diese Rolle können Sie Schwachstellen weder schließen noch löschen, und die Sammelaktion in der Übersicht bleibt verborgen.

Das Vier-Augen-Prinzip entfällt dann organisatorisch: Behebung und Abnahme liegen in derselben Hand. Halten Sie die beiden Schritte trotzdem getrennt – erst **Behoben** mit einem Eintrag in **Kommentar zur Behebung** (das Datum setzt OdinShield selbst), dann in einem zweiten Arbeitsgang **Geschlossen**. Die Historie belegt so auch später, dass eine Prüfung stattgefunden hat und nicht nur ein Haken gesetzt wurde.

Kommt später eine zweite Person hinzu, geben Sie ihr die ISB-Rolle und nehmen Sie sie sich selbst wieder weg – das Vier-Augen-Prinzip ist damit ohne weitere Umstellung wieder in Kraft.

## Sichtbarkeit von Schwachstellen

OdinShield filtert die Sichtbarkeit automatisch nach dem "Need-to-know"-Prinzip.

<details>
    <summary>Hintergrund: Wie die Sichtbarkeit berechnet wird</summary>
    <div>
        Ein Standard-Nutzer sieht eine Schwachstelle, wenn:
        <ul>
            <li>Er direkt als Verantwortlicher eingetragen ist.</li>
            <li>Er Verantwortlicher der betroffenen Software ist.</li>
            <li>Er Verantwortlicher einer betroffenen Asset-Gruppe ist (nur lesend, siehe unten).</li>
            <li>Er Mitglied einer Nutzergruppe ist, die Zugriff auf den Asset-Typ der betroffenen Asset-Gruppe hat.</li>
        </ul>
        Spezial-Rollen (ISB, Software-Owner, Hardware-Owner) sehen immer alle Schwachstellen. Hardware-Owner sehen sie <strong>nur lesend</strong>: Status, Verantwortliche und Kommentare ändern dürfen ISB, Software-Owner sowie Standard-Nutzer, die der Schwachstelle direkt, über die betroffene Software oder über eine Nutzergruppe zugeordnet sind. Die Verantwortung für eine Asset-Gruppe berechtigt nur zum Lesen.
    </div>
</details>

## Die Audit-Timeline als Nachweis

Jede Änderung an Schwachstellen, Vorkommen, Software, Asset-Gruppen, Asset-Typen, Herstellern, Nutzern und Nutzergruppen wird mit Person und Zeitstempel protokolliert. Die Historie eines einzelnen Objekts finden Sie auf dessen Detailseite im Reiter **Historie**. Die Historie über alle Objekttypen hinweg steht unter **Systemverwaltung → Audit-Timeline**.

**Voraussetzung:** Die Audit-Timeline und ihr Export sind der Rolle **Administrator** vorbehalten. Als ISB arbeiten Sie mit dem Reiter **Historie** auf der Detailseite des jeweiligen Objekts.

So belegen Sie einen Vorgang gegenüber einem Auditor:

<div class="manual-stepper">
    <div class="manual-step">
        <div class="manual-step-number">1</div>
        <h3 class="manual-step-title">Vorgang eingrenzen</h3>
        <div class="manual-step-content">
            Öffnen Sie die <strong>Audit-Timeline</strong> und kombinieren Sie die Filter für Aktion, Objekttyp, Nutzer und Zeitraum, bis nur noch der gesuchte Vorgang übrig ist.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">2</div>
        <h3 class="manual-step-title">Ergebnis prüfen</h3>
        <div class="manual-step-content">
            Zeilen mit Änderungsdetails erkennen Sie am Pfeil am rechten Rand. Klicken Sie auf den Pfeil – oder wählen Sie ihn mit der Tabulatortaste an und drücken Sie die Eingabetaste –, um die geänderten Felder mit Alt- und Neu-Wert zu sehen. Klicken Sie auf den Objektnamen, um zum Objekt selbst zu springen — sofern es noch existiert.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">3</div>
        <h3 class="manual-step-title">CSV exportieren</h3>
        <div class="manual-step-content">
            Klicken Sie auf <strong>CSV-Export</strong>. Die Datei enthält den <strong>gesamten gefilterten Bestand, nicht nur die angezeigte Seite</strong> — die eingestellte Seitengröße wirkt sich auf den Export nicht aus.
        </div>
    </div>
</div>

### Was die CSV-Datei enthält

Jede geänderte Eigenschaft steht in einer eigenen Zeile. Die Spalten sind: Zeitpunkt, Objekttyp, Objekt, Objekt-ID, Aktion, Feld, Alt, Neu und Geändert von. So lässt sich die Datei in einer Tabellenkalkulation sortieren und filtern. Einträge ohne Feldänderung — etwa eine reine Verknüpfung — erscheinen mit leeren Spalten Feld, Alt und Neu.

Die Datei ist mit Semikolon getrennt und UTF-8-kodiert. In Excel mit deutscher Spracheinstellung öffnet sie sich direkt in Spalten.

### Gelöschte Objekte

Die Historie überlebt das Objekt. Wurde ein Objekt gelöscht, zeigt die Timeline statt des Namens seine interne Nummer (`#412`) und dahinter das Kennzeichen **Gelöscht**; in der CSV-Datei steht dasselbe als `#412 (Gelöscht)` in der Spalte Objekt. Der Vorgang bleibt damit nachweisbar, auch wenn es das Objekt nicht mehr gibt.

## Wie geht's weiter?

Sind alle Nutzer eingerichtet? Dann stellen Sie sicher, dass Ihre Datenbasis aktuell bleibt:

<div class="manual-next-action">
    <div class="manual-next-action-label">Empfohlener nächster Schritt</div>
    <h3 class="manual-next-action-title">Häufig gestellte Fragen (FAQ)</h3>
    <p class="manual-next-action-text">Finden Sie Antworten auf typische Fragen zu Berechtigungen und zur Team-Organisation.</p>
    <a href="faq" class="manual-next-action-button">
        Zum Kapitel: FAQ
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
