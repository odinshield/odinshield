<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Schwachstellen verwalten

Schwachstellen sind das Herz von OdinShield. In diesem Kapitel lernen Sie, wie Sie Sicherheitslücken systematisch von der ersten Erfassung bis zur erfolgreichen Behebung begleiten.

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>Erfassen</h4>
        <p>Sicherheitslücken manuell anlegen oder automatisch über CSAF-Schnittstellen importieren.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Zuordnen</h4>
        <p>Schwachstellen mit Software und Asset-Gruppen verknüpfen, um <strong>Vorkommen</strong> zu definieren.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Bewerten</h4>
        <p>Das <strong>effektive Risiko</strong> priorisieren und Verantwortlichkeiten im Team festlegen.</p>
    </div>
</div>

## Der Lebenszyklus einer Schwachstelle

Jede Schwachstelle wandert durch einen klar definierten Prozess. Folgen Sie diesem Pfad, um Ihre IT-Landschaft sicher zu halten.

<div class="manual-stepper">
    <div class="manual-step">
        <div class="manual-step-number">1</div>
        <h3 class="manual-step-title">Erfassung & Zuweisung</h3>
        <div class="manual-step-content">
            Legen Sie eine neue Schwachstelle an. Weisen Sie eine <strong>verantwortliche Person</strong> zu. Bleibt das Feld leer, werden Sie automatisch selbst zuständig.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">2</div>
        <h3 class="manual-step-title">Vorkommen definieren</h3>
        <div class="manual-step-content">
            Wählen Sie die betroffenen <strong>Asset-Gruppen</strong> – und, sofern ein Produkt aus Ihrem Inventar betroffen ist, die zugehörige <strong>Software</strong>. OdinShield berechnet nun für jedes Vorkommen das effektive Risiko.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">3</div>
        <h3 class="manual-step-title">Behebung & Status</h3>
        <div class="manual-step-content">
            Sobald die Arbeit beginnt, setzen Sie den Status auf <strong>In Bearbeitung</strong>. Nach der technischen Lösung markieren Sie das Vorkommen als <strong>Behoben</strong>.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">4</div>
        <h3 class="manual-step-title">Abnahme (ISB-Check)</h3>
        <div class="manual-step-content">
            Der Informationssicherheitsbeauftragte (ISB) prüft die Behebung und schließt die Schwachstelle endgültig ab (<strong>Geschlossen</strong>) – möglich erst, wenn <strong>alle Vorkommen</strong> erledigt sind: <strong>Behoben</strong>, <strong>Risiko akzeptiert</strong> oder <strong>Nicht betroffen</strong>.
        </div>
    </div>
</div>

## Status verstehen: zwei Ebenen

In OdinShield gibt es Status auf **zwei Ebenen**, die unterschiedliche Fragen beantworten:

- **Vorkommen-Status** – „Was wurde auf *dieser* Asset-Gruppe getan?" (die operative Lage)
- **Schwachstellen-Status** – „Wie ist der Gesamtstand des Befunds?" (die übergeordnete Bewertung)

Beide dürfen sich unterscheiden – und das ist Absicht: Dieselbe Lücke kann auf Asset-Gruppe A **behoben** und auf Gruppe B als **Risiko akzeptiert** geführt werden. Der Schwachstellen-Status fasst den Gesamtbefund zusammen; er wird **nicht** automatisch aus den Vorkommen abgeleitet.

**Status eines Vorkommens** (pro Asset-Gruppe):

| Status | Bedeutung |
|--------|-----------|
| **Offen** | Betrifft die Gruppe, noch nichts unternommen. |
| **In Bearbeitung** | Die Behebung auf dieser Gruppe läuft. |
| **Behoben** | Technisch gelöst (Patch oder Workaround wirksam). |
| **Risiko akzeptiert** | Bewusst getragenes Risiko, kein Fix geplant. |
| **Nicht betroffen** | Geprüft – diese Gruppe ist nicht verwundbar. |
| **Zurückgestellt** | Behandlung bewusst verschoben (z. B. Wartungsfenster). |

**Status der Schwachstelle** (Gesamtbefund):

| Status | Bedeutung |
|--------|-----------|
| **Offen** | Erfasst, Bearbeitung noch nicht begonnen. |
| **In Bearbeitung** | Mindestens eine Gruppe wird bearbeitet. |
| **Behoben** | Alle Vorkommen sind erledigt – die ISB-Abnahme steht noch aus. |
| **Risiko akzeptiert** | Das Gesamtrisiko wird organisationsweit getragen. |
| **Zurückgestellt** | Der Gesamtbefund wurde verschoben. |
| **Geschlossen** | Der ISB hat den Fall final abgenommen (4-Augen-Prinzip). |

> **Wichtig:** Eine Schwachstelle lässt sich erst auf einen Endstatus (**Behoben**, **Risiko akzeptiert**, **Geschlossen**) setzen, wenn **alle** ihre Vorkommen erledigt sind. Ein **zurückgestelltes** oder noch offenes Vorkommen verhindert das. **Geschlossen** ist allein dem ISB vorbehalten. Das gilt für das Schließen ebenso wie für das Wiederöffnen: Bearbeiten Sie ohne ISB-Rolle eine geschlossene Schwachstelle, ist der Status gesperrt; die übrigen Felder können Sie weiterhin bearbeiten. **Geschlossen** existiert nur auf Schwachstellen-Ebene, nicht beim einzelnen Vorkommen. Ebenfalls dem ISB vorbehalten ist das **Löschen** einer Schwachstelle; alle anderen Rollen können einen Befund nur in einen Endstatus überführen, nicht entfernen. Umgekehrt gibt es **Nicht betroffen** nur beim einzelnen Vorkommen: Ein Befund als Ganzes ist nie „nicht betroffen", sonst wäre er nicht erfasst worden.

### „Nicht betroffen" richtig einsetzen

Eine Software läuft laut Inventar auf fünf Asset-Gruppen – die Schwachstelle greift aber nur bei einer bestimmten Konfiguration, und drei Gruppen sind nachweislich nicht verwundbar. Für diese drei setzen Sie das Vorkommen auf **Nicht betroffen**.

Das grenzt sich klar von zwei Nachbarn ab:

- **Nicht Risiko akzeptiert.** Dort *ist* die Gruppe betroffen, das Risiko wird bewusst getragen. Bei **Nicht betroffen** gibt es kein Risiko zu tragen.
- **Nicht abwählen.** Sie könnten die Asset-Gruppe auch aus der Schwachstelle entfernen. Damit verschwindet aber der Nachweis, dass jemand hingeschaut hat – und beim nächsten Importlauf taucht die Gruppe wieder auf, weil die Importer Gruppen nur hinzufügen. **Nicht betroffen** bleibt bestehen.

Zwei Auswirkungen, die Sie kennen sollten:

- Ein nicht betroffenes Vorkommen fließt **nicht** in das effektive Risiko der Schwachstelle ein. Ist die einzige Gruppe nicht betroffen, sinkt das Risiko der Schwachstelle auf 0.
- Ein nicht betroffenes Vorkommen zählt **nicht** als behoben (die Anzeige „x von y behoben" ignoriert es), verhindert aber auch nicht den Abschluss der Schwachstelle.

Nutzen Sie das Notizfeld des Vorkommens für die Begründung – zum Beispiel „Modul nicht einkompiliert" oder „Feature auf diesen Hosts deaktiviert". Das ist der eigentliche Nachweis.

## Betroffene Asset-Gruppen wählen: zwei Wege

Welche Asset-Gruppen Sie auswählen dürfen, hängt davon ab, ob Sie eine Software angeben. OdinShield schaltet automatisch zwischen beiden Wegen um – ein Hinweis unter dem Auswahlfeld sagt Ihnen jeweils, welcher gerade gilt.

### Mit Software – das Inventar gibt die Auswahl vor

Sobald Sie eine Software auswählen, beschränkt sich die Liste der Asset-Gruppen auf jene, in denen dieses Produkt laut Inventar tatsächlich läuft. Damit kann keine Schwachstelle behaupten, ein Produkt sei irgendwo betroffen, wo es gar nicht installiert ist.

Fehlt eine Gruppe in der Liste, ist das ein Hinweis auf eine Lücke im Inventar – nicht auf einen Fehler im Formular. Der Weg dorthin:

1. Öffnen Sie **Inventar → Asset-Gruppen** und wählen Sie die betreffende Gruppe.
2. Tragen Sie die Software im Feld **Software** ein.
3. Kehren Sie zur Schwachstelle zurück – die Gruppe steht nun zur Auswahl.

### Ohne Software – freie Auswahl

Geben Sie keine Software an, können Sie jede aktive Asset-Gruppe frei auswählen. Dieser Weg ist ausdrücklich vorgesehen für Befunde, die sich nicht sinnvoll inventarisieren lassen:

- eine Schwachstelle in einer einzelnen Bibliothek eines Linux-Systems, deren Version Sie nicht kennen,
- ein Pentest-Befund an einer Komponente, die (noch) nicht im Inventar steht,
- eine Meldung, bei der zunächst nur das betroffene System bekannt ist.

Das Inventar ist ein **Produktinventar**, keine vollständige Stückliste aller installierten Bibliotheken. Sie müssen also nicht jede Lib erfassen, nur um eine Schwachstelle dokumentieren zu können.

> **Gut zu wissen:** Auch ohne Software erhält die Schwachstelle ein vollwertiges effektives Risiko. Die Berechnung stützt sich auf Kritizität, Exposition und Datenklassifikation der **Asset-Gruppe** – nicht auf die Software. Was ohne Inventarbezug entfällt, ist allein der automatische Import: ohne Inventarbezug findet kein Advisory diese Schwachstelle von selbst – der Import trifft nur Software mit Pflege-Quelle **Import** und gepflegten Keys und Version.

### Wenn ein Vorkommen als „Nicht durch Inventar gedeckt" markiert ist

Ändert jemand später das Inventar – etwa weil ein Produkt aus einer Asset-Gruppe entfernt wird –, bleiben bereits erfasste Vorkommen erhalten. Sie werden **nicht** gelöscht, denn an ihnen hängen Status, Behebungsdatum und die vollständige Änderungshistorie. Stattdessen erscheint in der Tabelle **Status je Asset-Gruppe** ein Hinweis-Etikett.

Das Etikett ist kein Fehler, sondern eine Frage an Sie: War der Befund damals korrekt und ist inzwischen erledigt, lassen Sie ihn als Nachweis stehen. War die Zuordnung falsch, wählen Sie die Asset-Gruppe im Formular ab.

## Verantwortlichkeiten festlegen

Jede Schwachstelle braucht eine verantwortliche Person, die die Behebung treibt.

- **Zuweisung:** Sie können eine Person direkt beim Erfassen oder später per Dropdown zuweisen.
- **Benachrichtigung:** Die zugewiesene Person erhält (bei aktiver Einstellung) eine E-Mail und sieht den Zähler in der **Glocke** (Topbar).
- **Auto-Zuweisung:** Bleibt das Feld leer, werden Sie automatisch selbst als Verantwortlicher eingetragen.
- **Fälligkeitsdatum:** Das optionale Datum, bis zu dem die Behebung abgeschlossen sein soll. Sie tragen es im Formular ein und sehen es in der Detailansicht. OdinShield löst daran keine Automatik aus – kein Statuswechsel, keine Erinnerung –, es ist Ihre Planungsgröße für die tägliche Priorisierung (siehe [Tipps](tipps)). In der Schwachstellen-Liste erscheint das Datum nicht als Spalte.

## Das effektive Risiko verstehen

OdinShield berechnet automatisch, wie dringend gehandelt werden muss. Das **effektive Risiko** kombiniert die technische Schwere der Lücke mit der Kritizität Ihrer Asset-Gruppe.

| Bereich | Klasse | Aktion |
|---------|--------|--------|
| **≥ 120** | Kritisch | Sofortiges Handeln erforderlich |
| **≥ 60** | Hoch | Behebung innerhalb weniger Tage |
| **≥ 20** | Mittel | Geplante Behebung in den nächsten Wochen |
| **< 20** | Niedrig | Routine-Pflege |

> **Wichtig:** Ein Risiko gilt immer **pro Vorkommen**. Dieselbe Lücke kann auf einem öffentlichen Webserver kritisch sein, in einem isolierten Testnetzwerk hingegen nur niedrig.

### Aktive Ausnutzung verdoppelt das Risiko

Ist eine Schwachstelle als **Wird aktiv ausgenutzt** gekennzeichnet, verdoppelt OdinShield ihr effektives Risiko auf jedem Vorkommen. Aus einem Wert von 32 (Mittel) wird so 64 (Hoch). Der Wertebereich reicht damit von 1 bis 480; die Grenzen der Klassen in der Tabelle oben bleiben unverändert.

- **Abweichung von BSI-CS 093:** Die BSI-Formel berücksichtigt nicht, ob eine Lücke bereits angegriffen wird; OdinShield ergänzt das bewusst.
- **Herkunft egal:** Die Verdopplung gilt, sobald das Kennzeichen gesetzt ist – ob durch den täglichen Abgleich mit der CISA-KEV-Liste oder von Hand im Formular, etwa nach einer Warnung des BSI.
- **Entfernen:** Nehmen Sie den Haken im Formular heraus, sinkt das Risiko sofort wieder. Steht eine CVE des Eintrags weiter in der KEV-Liste und ist der Eintrag nicht abgeschlossen, setzt der nächste tägliche Lauf das Kennzeichen – und damit die Verdopplung – erneut.
- **EPSS fließt nicht ein:** Der EPSS-Score ist eine täglich schwankende Schätzung und fehlt für viele CVEs. Er dient Ihnen zur Einschätzung, verändert das effektive Risiko aber nicht.

## Schweregrad einer importierten Schwachstelle anpassen

Der CSAF-Import leitet den Schweregrad aus dem CVSS-Wert der Meldung ab, ohne CVSS-Wert aus ihrer eigenen Einstufung. Passt diese Einstufung nicht zu Ihrer Umgebung – etwa weil der betroffene Dienst nirgends erreichbar ist –, stufen Sie die Schwachstelle selbst ein. Dafür brauchen Sie das Recht, die Schwachstelle zu bearbeiten (siehe Abschnitt **Verantwortlichkeiten festlegen** oben).

1. Öffnen Sie die Schwachstelle zum Bearbeiten und wählen Sie einen anderen **Schweregrad**. Unter dem Feld steht der **Wert der Quelle**.
2. Tragen Sie unter **Begründung der abweichenden Einstufung** ein, warum Sie abweichen. Ohne Begründung lässt sich eine abweichende Einstufung nicht speichern.
3. Speichern Sie. Das effektive Risiko wird mit Ihrem Schweregrad neu berechnet.

- **Neue Versionen der Meldung:** Ihre Einstufung bleibt erhalten. Nur wenn die Quelle in einer neuen Version höher einstuft als in der vorigen Version **und** höher als Sie selbst, gilt wieder der Wert der Quelle. Dann hängt der Import einen englischen Vermerk („Severity override lifted …") an den **Kommentar zur Behebung** an, mit Ihrer bisherigen Einstufung und Begründung, damit Sie neu bewerten können. Eine Einstufung über dem Wert der Quelle wird nie herabgesetzt; erreicht die Quelle Ihre Einstufung, entfällt die Begründung.
- **CVSS-Wert:** Er bleibt der Messwert der Quelle und ist bei importierten Schwachstellen nicht änderbar.
- **Nachvollziehbarkeit:** Die Detailansicht zeigt **Manuell eingestuft** mit dem Wert der Quelle und Ihrer Begründung; wer wann geändert hat, steht im Reiter **Historie**.
- **Zurück zur Quelle:** Wählen Sie wieder den Wert der Quelle; die Begründung entfällt dann.
- **Manuell erfasste Schwachstellen:** Hier gibt es keinen Wert der Quelle. **Schweregrad** und **CVSS-Wert** ändern Sie frei, eine Begründung ist nicht nötig.

## Eigene Notizen vom importierten Advisory-Text trennen

Bei automatisch importierten Schwachstellen stammt der beschreibende Text aus der Quelle – einer CSAF-Meldung des BSI oder eines Herstellers. Dieser Text steht im Reiter **Advisory** der Detailansicht unter **Beschreibung aus der Quelle** und ist dort **nicht bearbeitbar**: Jeder Importlauf schreibt ihn vollständig neu, eine Änderung wäre beim nächsten Lauf verloren.

Ihr eigener Text gehört in das Feld **Beschreibung** im Reiter *Details*. Kein Import überschreibt dieses Feld – was Sie dort schreiben, bleibt.

Der Reiter erscheint nur, wenn zu dem Eintrag Importdaten vorliegen. Rein manuell gepflegte Schwachstellen haben ihn nicht.

> **Der Import setzt nie selbst auf „Behoben".** Meldet ein Advisory, dass für Ihr Produkt ein Fix vorliegt, erhält der Eintrag das Kennzeichen **Sicherheitspatch verfügbar** und – bei einem bereits bestehenden Eintrag – einen Hinweis im Feld **Kommentar zur Behebung** – der Status bleibt unverändert. Ob der Patch tatsächlich eingespielt ist, bestätigt immer ein Mensch. Unberührt bleiben Status, Beschreibung, Verantwortliche, Fälligkeitsdatum und Behebungspriorität. In zwei Feldern schreibt allerdings der **CSAF-Import** mit: Den Fix-Hinweis hängt er an **Kommentar zur Behebung** an, und sobald eine neue Advisory-Version erscheint, stellt er einen Block vor Ihren Text in **Minderung**. Ihr eigener Eintrag bleibt erhalten, rutscht dort aber nach unten. Der Fix-Hinweis ist englisch („Fix available per advisory …"); der Minderungs-Block trägt eine deutsche Kopfzeile („--- CSAF-Update … ---") und darunter den Originaltext des Advisories, meist englisch. Übersetzt wird nichts. Der Fix-Hinweis nennt die Version, ab der das Advisory das Produkt als behoben führt („fixed from 7.6.4").
>
> **Der Hersteller korrigiert sich:** Führt eine neue Advisory-Version Ihre Software als *nicht betroffen* und bei keiner Lücke dieser Version mehr als betroffen? Dann hängt der Import einen englischen Hinweis („Vendor reports not affected per advisory …") an **Kommentar zur Behebung** an, einmal je Advisory-Version – aber nur bei noch nicht abgeschlossenen Einträgen (nicht bei **Behoben**, **Risiko akzeptiert** oder **Geschlossen**) und nur, wenn das korrigierte Advisory die Import-Schwelle erreicht (Standard: Schweregrad **Mittel**). Status, Vorkommen und Software-Zuordnung bleiben, geschlossen wird nichts automatisch. Prüfen Sie, ob die Entwarnung für Ihre Umgebung gilt, und setzen Sie die betroffenen Vorkommen dann selbst auf **Nicht betroffen**. Sind alle Vorkommen erledigt, kann der ISB den Eintrag schließen. Ein Advisory, das Ihre Software nur als nicht betroffen führt, legt nie einen Eintrag an.

<details>
    <summary>Hintergrund: Herkunftsdaten im Reiter „Advisory“</summary>
    <div>
        Der Reiter zeigt neben dem Text auch, woher der Eintrag stammt und wie aktuell er ist:
        <ul>
            <li><strong>CSAF-Quellen:</strong> Anbieter, Dokumentnummer, Version des Advisories und der Zeitpunkt des letzten Abgleichs. Steigt die Version, wurde die Meldung von der Quelle überarbeitet.</li>
            <li><strong>Zuordnungsgrund:</strong> Der Text unter <strong>Beschreibung aus der Quelle</strong> endet mit einem Abschnitt <code>## Matched because</code> (englisch, vom Import geschrieben). Er nennt je Software den gemeldeten Versionsbereich, die verglichene Inventar-Version und den Grund des Treffers – etwa dass Ihre Version nur auf Major/Minor-Ebene gepflegt ist und den Bereich überlappt. Konnte der Versionstext der Meldung nicht gelesen werden, steht hier der Originaltext; die Meldung gilt dann für alle Versionen.</li>
            <li><strong>Gebündelte CVE-IDs:</strong> Eine CSAF-Meldung fasst häufig mehrere CVEs zusammen – hier sehen Sie alle, die zu diesem Eintrag gehören.</li>
        </ul>
        Extrem lange Advisory-Texte werden gekürzt. Am Ende steht dann ein englischer Marker der Form
        <code>[… truncated at 131,072 characters — full advisory: …]</code> mit der Adresse des vollständigen Originals.
        Die Adresse ist dort nicht anklickbar – kopieren Sie sie in Ihren Browser. Bei manchen Quellen fehlt sie;
        dann hilft die Advisory-URL im Reiter <em>Details</em>.
    </div>
</details>

## Effizient arbeiten: Filter & Suche

Nutzen Sie die Listenansicht, um sich auf das Wesentliche zu konzentrieren:

- **Mir zugewiesen:** Finden Sie sofort Ihre persönlichen Aufgaben.
- **Risiko-Sortierung:** Standardmäßig stehen die brennendsten Themen oben.
- **EPSS und aktive Ausnutzung:** In der Spalte **CVE-ID** steht unter der CVE der **EPSS**-Wert in Prozent. Ein Sternchen (\*) hinter dem Wert bedeutet: Der Eintrag bündelt mehrere CVEs, angezeigt wird der höchste EPSS-Wert unter ihnen. Wenn Sie mit der Maus auf den Wert zeigen, nennt ein Hinweis die Anzahl der CVEs. Alle CVEs finden Sie in der Detailansicht unter **Gebündelte CVE-IDs**. Ein Flammen-Symbol neben dem **effektiven Risiko** bedeutet **Aktiv ausgenutzt** – auch bei Einträgen ohne CVE. Auf schmalen Bildschirmen ist die Spalte **CVE-ID** ausgeblendet; die Filter funktionieren trotzdem.
  - **Sortieren:** Klicken Sie im Spaltenkopf auf **EPSS**. Der erste Klick sortiert aufsteigend, ein zweiter zeigt die höchsten Werte oben. Einträge ohne EPSS stehen in beiden Richtungen am Ende.
  - **Filtern:** Wählen Sie im Filter **Ausnutzung: alle** den Eintrag **Aktiv ausgenutzt**. Im Filter **EPSS: alle** wählen Sie einen Mindestwert (**EPSS ab 1 %**, **EPSS ab 10 %**, **EPSS ab 50 %**). Beide lassen sich mit den übrigen Filtern kombinieren.
- **Spaltenbreiten:** Ab 1200 Pixeln Bildschirmbreite ziehen Sie den rechten Rand eines Spaltenkopfs, um die Spalte breiter oder schmaler zu machen. Per Tastatur setzen Sie den Fokus auf den Ziehgriff und drücken Pfeil links oder Pfeil rechts. OdinShield merkt sich die Breiten in diesem Browser – auch nach dem Abmelden und auch für andere Konten, die sich dort anmelden. Sobald Sie eine Breite geändert haben, erscheint neben der Auswahl der Einträge pro Seite die Schaltfläche **Spaltenbreiten zurücksetzen**. Ist die Tabelle breiter als der Bildschirm, können Sie sie auch über den Scrollbalken direkt über dem Tabellenkopf seitlich verschieben.
- **Filter-Links:** Alle Filter werden in der URL gespeichert. Kopieren Sie die Browser-URL, um eine gefilterte Ansicht mit Kollegen zu teilen.
- **Volltextsuche:** Die Suche erfasst **Titel**, **CVE-ID**, Ihre eigene **Beschreibung**, **Minderung** und **Kommentar zur Behebung** – und zusätzlich den importierten Advisory-Text. Ein Produktname oder eine Formulierung aus der Meldung führt Sie also direkt zum Eintrag.
- **Suche nach Nummer:** Die Spalte **Nr.** ist die Kurzreferenz für E-Mail und Telefon. Geben Sie die Nummer mit `#` ins Suchfeld ein, etwa `#412`: Der Eintrag mit dieser Nummer steht dann ganz oben. Ohne `#` findet die Suche zusätzlich Einträge, deren Text die Ziffern enthält, etwa eine CVE-ID. Andere gesetzte Filter, etwa der Status, gelten weiter – setzen Sie sie zurück, wenn der Eintrag fehlt. Wie bei jeder Suche erscheinen nur Einträge, die Sie sehen dürfen. Dieselbe Nummer steht in der Detailansicht vor dem Titel.

<details>
    <summary>Hintergrund: Automatische CSAF-Importe</summary>
    <div>
        OdinShield zieht zweimal täglich Sicherheits-Hinweise von den CSAF-Quellen, die Ihre Administration eingetragen hat (etwa BSI, Siemens, SICK, Schneider Electric). Eine Schwachstelle wird automatisch angelegt, wenn Hersteller und Produkt einer Software aus Ihrem Inventar mit der Meldung übereinstimmen <strong>und</strong> die eingesetzte Version im gemeldeten Bereich liegt. Wie der Abgleich arbeitet und welche Unschärfe er bewusst in Kauf nimmt, beschreibt das Kapitel <a href="inventar">Inventar pflegen</a>.
        <br><br>
        Meldungen, die ein Paket nur <em>auf einer Plattform</em> beschreiben (das Modell von Red Hat: „openssl auf RHEL 9"), ordnet OdinShield zurzeit nicht zu – dort fehlt eine vergleichbare Version. Red Hat liefert deshalb in der Praxis keine Treffer.
    </div>
</details>

<details>
    <summary>Hintergrund: EPSS-Score und „Wird aktiv ausgenutzt"</summary>
    <div>
        Einmal täglich ergänzt OdinShield <strong>jede Schwachstelle mit CVE-ID, die noch nicht abgeschlossen ist</strong> (Status <strong>Offen</strong>, <strong>In Bearbeitung</strong> oder <strong>Zurückgestellt</strong>) – egal ob importiert oder von Hand erfasst – um zwei Kennzahlen aus den Originalquellen:
        <ul>
            <li><strong>EPSS-Score:</strong> Die von FIRST.org geschätzte Wahrscheinlichkeit (in Prozent), dass die Schwachstelle in den nächsten 30 Tagen tatsächlich ausgenutzt wird. Bündelt ein Eintrag mehrere CVEs, steht der höchste Wert.</li>
            <li><strong>Wird aktiv ausgenutzt:</strong> Wird gesetzt, sobald eine CVE des Eintrags in der CISA-KEV-Liste (bekannte, tatsächlich ausgenutzte Schwachstellen) geführt wird. Von selbst nimmt OdinShield das Kennzeichen nie zurück – auch nicht, wenn die CVE später aus der Liste fällt. Wie es das effektive Risiko verdoppelt und was beim Abwählen passiert, steht oben unter „Aktive Ausnutzung verdoppelt das Risiko“.</li>
        </ul>
        Diese Anreicherung legt <strong>keine</strong> Schwachstellen an und ändert weder Status noch Schweregrad. Einträge in <strong>Behoben</strong>, <strong>Risiko akzeptiert</strong> oder <strong>Geschlossen</strong> werden nicht mehr aktualisiert.
    </div>
</details>

## Sammelaktion: mehrere Schwachstellen auf einmal

> **Voraussetzung:** Nur als **ISB** verfügbar (4-Augen-Prinzip), da die Aktion Schwachstellen auch schließen kann.

In der Übersicht lassen sich mehrere Schwachstellen gemeinsam auf einen Status setzen – etwa um nach einem Patch-Tag oder einem CSAF-Import zügig aufzuräumen.

So gehen Sie vor:

1. **Auswählen:** Haken Sie in der Liste die gewünschten Zeilen an – oder nutzen Sie die Checkbox im Tabellenkopf, um **alle Einträge der aktuellen Seite** zu wählen. Die Auswahl ist seitengebunden und wird beim Filtern, Sortieren oder Seitenwechsel zurückgesetzt.
2. **Aktionsleiste:** Sobald mindestens eine Zeile markiert ist, erscheint oben eine Leiste mit der Anzahl. Über **Leeren** verwerfen Sie die Auswahl, über **Status setzen** öffnen Sie den Dialog.
3. **Zielstatus wählen:** Legen Sie fest, welchen Status die noch nicht erledigten Vorkommen (**Offen**, **In Bearbeitung**, **Zurückgestellt**) erhalten. Jede Option nennt auch die Folge für die Schwachstelle selbst, zum Beispiel „Behoben – Schwachstelle wird geschlossen". Der Einleitungssatz des Dialogs passt sich der Auswahl an. Optional ergänzen Sie einen **Kommentar**. OdinShield hängt ihn an das Feld **Kommentar zur Behebung** an und setzt Datum, Ihren Namen und Ihren Benutzernamen davor, etwa `[2026-10-24] Erika Muster (emuster): …`.
4. **Übernehmen:** Die betroffenen Zeilen aktualisieren sich direkt. Die Ergebnismeldung nennt drei Zahlen: **aktualisiert** (erfolgreich gesetzt), **übersprungen** (z. B. bereits geschlossen) und **mit Konflikt** (jemand hat den Eintrag zwischenzeitlich geändert – diese bleiben unverändert und können erneut bearbeitet werden).

Der gewählte Zielstatus steuert beide Ebenen:

| Zielstatus der Vorkommen | Wirkung auf die Schwachstelle |
|--------------------------|-------------------------------|
| **Behoben** | nicht erledigte Vorkommen → behoben, Schwachstelle wird **geschlossen** (ISB-Abnahme) |
| **Risiko akzeptiert** | nicht erledigte Vorkommen → Risiko akzeptiert, Schwachstelle wird **geschlossen** |
| **Zurückgestellt** | nicht erledigte Vorkommen → zurückgestellt, Schwachstelle wird **zurückgestellt** (nicht geschlossen) |

Bereits erledigte Vorkommen (**Behoben**, **Risiko akzeptiert** oder **Nicht betroffen**) bleiben unangetastet; schon **geschlossene** Schwachstellen werden übersprungen.

## Wie geht's weiter?

Bereiten Sie Ihre Datenbasis vor, um Schwachstellen präzise zuordnen zu können:

<div class="manual-next-action">
    <div class="manual-next-action-label">Empfohlener nächster Schritt</div>
    <h3 class="manual-next-action-title">Inventar & Assets pflegen</h3>
    <p class="manual-next-action-text">Erfahren Sie, wie Sie Software und Asset-Gruppen anlegen, um die Grundlage für die Risikoberechnung zu schaffen.</p>
    <a href="inventar" class="manual-next-action-button">
        Zum Kapitel: Inventar
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
