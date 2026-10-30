<!--
SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Inventar pflegen

Das Inventar ist das Fundament von OdinShield. Ohne sauber gepflegte Software und Asset-Gruppen bleiben Schwachstellen abstrakt — mit ihnen werden sie greifbar und bewertbar.

<div class="manual-feature-grid">
    <div class="manual-feature-card">
        <h4>Software</h4>
        <p>Erfassen Sie Ihre Anwendungen und Betriebssysteme inklusive Version und Support-Status.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Asset-Gruppen</h4>
        <p>Bündeln Sie Ihre IT-Infrastruktur und definieren Sie die Kritikalität für Ihr Geschäft.</p>
    </div>
    <div class="manual-feature-card">
        <h4>Hersteller</h4>
        <p>Verwalten Sie Anbieter zentral, um Ihre Software-Daten konsistent zu halten.</p>
    </div>
</div>

## Aufbau Ihres Inventars

Folgen Sie dieser Reihenfolge, um eine solide Datenbasis zu schaffen.

<div class="manual-stepper">
    <div class="manual-step">
        <div class="manual-step-number">1</div>
        <h3 class="manual-step-title">Hersteller & Software</h3>
        <div class="manual-step-content">
            Legen Sie Ihre Softwareprodukte an. Wichtig: Pflegen Sie <strong>Vendor-Key</strong>, <strong>Product-Key</strong> und <strong>Version</strong> so, wie Sicherheitsmeldungen das Produkt nennen – darüber ordnet OdinShield CSAF-Advisories automatisch zu (Abschnitt „Keys und Version so pflegen, dass Advisories treffen").
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">2</div>
        <h3 class="manual-step-title">Asset-Gruppen definieren</h3>
        <div class="manual-step-content">
            Gruppieren Sie Ihre Assets (z. B. "Webserver Produktion"). Legen Sie fest, wie <strong>exponiert</strong> (Internet vs. Intern) und wie <strong>kritisch</strong> diese Gruppe ist.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">3</div>
        <h3 class="manual-step-title">Verknüpfung</h3>
        <div class="manual-step-content">
            Sobald eine Software einer Asset-Gruppe zugeordnet wird, ist OdinShield bereit, Schwachstellen für genau diese Kombination zu überwachen.
        </div>
    </div>
</div>

> **Diese Zuordnung ist der einzige Pflegeort.** Das Feld **Software** in der Asset-Gruppe legt fest, welche Asset-Gruppen beim Erfassen einer Schwachstelle zu diesem Produkt überhaupt zur Auswahl stehen. Fehlt dort ein Eintrag, lässt sich die Schwachstelle für diese Gruppe nicht anlegen — pflegen Sie die Zuordnung zuerst hier. Umgekehrt gilt: Über das Schwachstellen-Formular entsteht **keine** Inventar-Verknüpfung.

## Zuständigkeiten pflegen

Nutzen Sie die Zuweisung von **Verantwortlichen**, um die Pflege Ihrer Daten sicherzustellen:

* **Software:** Die verantwortliche Person kümmert sich um Versions-Updates und die Korrektheit von Keys, Alias-Namen und Version.
* **Asset-Gruppe:** Wer kennt diese Infrastruktur technisch am besten? Diese Person wird bei zugehörigen Schwachstellen mitberechtigt.

## Der Review-Zyklus

OdinShield hilft Ihnen, Ihr Inventar aktuell zu halten. Die **Pflege-Quelle** einer Software legt fest, wie deren Schwachstellen gepflegt werden: **Import** (automatisch über den CSAF-Import), **Manuell** (durch Sie) oder **Keine**. Software mit der Pflege-Quelle **Manuell** muss regelmäßig geprüft werden.

* **Dashboard-Warnung:** Software mit der Pflege-Quelle **Manuell**, die länger als 30 Tage nicht geprüft wurde, erscheint als "überfällig".
* **Prüfung festhalten:** Haben Sie eine manuell gepflegte Software gegen die Herstellermeldungen geprüft, klicken Sie auf **Als geprüft markieren** – im Zeilenmenü der Software-Liste oder direkt im Dashboard-Widget „Prüfung überfällig". Das setzt „Zuletzt geprüft" auf heute. Die Schaltfläche sehen ISB, Software-Owner und die verantwortliche Person der Software.
* **Automatisierung:** Setzen Sie die Pflege-Quelle auf **Import** — dann prüft der CSAF-Import die Software gegen jede Meldung. Jeder erfolgreiche Import-Lauf gilt als Review, solche Software erscheint nie als überfällig.

## Keys und Version so pflegen, dass Advisories treffen

Der CSAF-Import vergleicht drei Angaben aus dem Inventar mit jeder Sicherheitsmeldung: den **Vendor-Key** des Herstellers, den **Product-Key** der Software und die **Version**. Ein Advisory trifft eine Software, wenn Hersteller und Produkt übereinstimmen **und** die eingesetzte Version im gemeldeten Bereich liegt. Voraussetzung: Pflege-Quelle **Import**, Software und Hersteller **Aktiv**, mindestens eine zugeordnete Asset-Gruppe. Fehlt die Asset-Gruppe, trifft der Import die Software zwar, legt aber keine Schwachstelle an; die Software-Liste zeigt dann ein Warnsymbol hinter dem Namen und die Detailansicht den Hinweis **Keiner Asset-Gruppe zugeordnet, Import legt keine Schwachstellen an**.

* **Schreiben Sie Vendor-Key und Product-Key so, wie Advisories das Produkt nennen:** `fortinet` / `fortios`, `siemens` / `simatic_s7_1500`, `opnsense` / `opnsense`. Groß-/Kleinschreibung spielt keine Rolle; Leer- und Sonderzeichen werden zu `_`. „FortiOS" und `fortios` sind gleich, „SICK AG" wird aber zu `sick_ag` und ist **nicht** gleich `sick`. Trägt eine Meldung eine CPE-ID, nimmt OdinShield deren Hersteller- und Produkt-Anteil; sonst die Namen aus der Meldung.
* **Schlagen Sie die Schreibweise in der NVD CPE Search nach:** Die [NVD CPE Search](https://nvd.nist.gov/products/cpe/search) zeigt, unter welcher CPE-ID ein Produkt geführt wird; die Formulare für Software und Hersteller verlinken sie unter dem Key-Feld. Übernehmen Sie aus der CPE-ID nur zwei Teile: Aus `cpe:2.3:o:fortinet:fortios:7.6.3:…` wird der Vendor-Key `fortinet` und der Product-Key `fortios`. Ein Feld für die ganze CPE-ID gibt es nicht; die Version pflegen Sie getrennt.
* **Ergänzen Sie Alias-Namen, wo die Schreibweise abweicht:** am Hersteller etwa „SICK AG" für den Key `sick`, an der Software „CorivaEngine" für `coriva_engine`. Ein Alias zählt wie der Key. Ähnliche, aber nicht gleiche Namen erzeugen **keinen** Treffer. Der Import vermerkt sie nur im Anwendungsprotokoll als Alias-Kandidaten – fragen Sie Ihre Administration danach oder legen Sie den Alias gleich an.
* **Pflegen Sie die Version mit führendem Zahlenteil:** Verglichen wird nur dieser. „7.6.3 build 1234" zählt als 7.6.3, „2024 R2" als 2024; die Detailansicht zeigt das als **verglichen als 7.6.3**. Major/Minor genügt: eine Software mit Version „7.6" gilt als jede 7.6.x. Steht am Anfang keine Zahl („latest"), zeigt die Software-Liste bei Pflege-Quelle **Import** ein Warnsymbol und die Detailansicht den Hinweis **Version nicht vergleichbar, Treffer ungefiltert** – dann wird jede Meldung zu diesem Produkt importiert.

> **Bewusste Unschärfe:** Meldet ein Advisory „betroffen unterhalb 7.6.4" und Ihr Inventar steht auf „7.6", entsteht eine Schwachstelle – auch wenn Sie tatsächlich 7.6.5 einsetzen. OdinShield meldet lieber einmal zu viel als einmal zu wenig. Im Reiter **Advisory** der Schwachstelle endet der Text unter **Beschreibung aus der Quelle** mit einem Abschnitt `## Matched because` (englisch, vom Import geschrieben); er nennt den verglichenen Bereich und Ihre Inventar-Version. Liegt Ihr Patch-Stand bereits über der Grenze, setzen Sie das Vorkommen auf **Nicht betroffen** – oder pflegen Sie die Version mit Patch-Stand, dann bleibt die Meldung künftig aus.

**Wann der Import prüft:** Jede Meldung wird geprüft, wenn sie erscheint oder aktualisiert wird – gegen das Inventar, das in diesem Moment besteht. Legen Sie eine Software neu an oder korrigieren Sie Keys, Version oder Asset-Gruppe, wirkt das für alle Meldungen ab dem nächsten Importlauf. Ältere Meldungen prüft OdinShield nicht von selbst noch einmal. Soll eine neue Software auch gegen die Meldungen der letzten Wochen geprüft werden, bitten Sie Ihre Administration, die Meldungen des Anbieters erneut lesen zu lassen: Der Konsolenbefehl `odinshield:csaf:rewind bsi_wid --days=90` lässt die nächsten Läufe die BSI-Meldungen der letzten 90 Tage noch einmal lesen; große Mengen verteilen sich auf mehrere Läufe. Bereits erfasste Meldungen bleiben dabei unverändert.

<details>
    <summary>Nicht abwärtskompatibel: Wegfall des CPE-Felds (Version 2.2.0)</summary>
    <div>
        <strong>Für Administratoren, einmalig nach dem Update auf 2.2.0.</strong> Bis Version 2.1 trug jede Software ein Feld <strong>CPE</strong>, über das der Import zuordnete. Dieses Feld gibt es nicht mehr; die Zuordnung läuft ausschließlich über Keys, Alias-Namen und Version. Beim Update prüft OdinShield jede bisherige CPE gegen die Keys und meldet Abweichungen im Update-Protokoll – <strong>korrigiert wird nichts automatisch</strong>. Prüfen Sie nach dem Update, ob Vendor-Key und Product-Key so lauten, wie Advisories das Produkt nennen, und ergänzen Sie Alias-Namen, wo die Schreibweise abweicht. Der Konsolenbefehl <code>odinshield:vulnerability:recheck</code> bewertet jede offene importierte Schwachstelle neu und meldet je Zeile, ob sie noch treffen würde – er ändert nichts.
    </div>
</details>

## OT-Geräte abbilden

Steuerungen, Sensorik und andere OT-Komponenten brauchen kein eigenes Modell – das Inventar bildet sie mit denselben drei Bausteinen ab wie Server und Anwendungen. Am Beispiel einer SPS in der Fertigung:

<div class="manual-stepper">
    <div class="manual-step">
        <div class="manual-step-number">1</div>
        <h3 class="manual-step-title">Asset-Typ „Steuerung"</h3>
        <div class="manual-step-content">
            Legen Sie unter <strong>Inventar → Asset-Typen</strong> den Typ „Steuerung" an (Hardware-Owner oder ISB). Über den Asset-Typ steuern Sie später auch, welche Nutzergruppe diese Geräte sieht.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">2</div>
        <h3 class="manual-step-title">Asset-Gruppe „SPS Halle 3"</h3>
        <div class="manual-step-content">
            Asset-Typ Steuerung, geschäftliche Wichtigkeit <strong>Hoch</strong> (Produktionsstillstand), Netzwerk-Zugänglichkeit <strong>Isoliert</strong> (eigenes Produktionsnetz ohne Internet-Zugang), Vertraulichkeit nach Ihrer Einstufung.
        </div>
    </div>
    <div class="manual-step">
        <div class="manual-step-number">3</div>
        <h3 class="manual-step-title">Firmware als Software</h3>
        <div class="manual-step-content">
            Hersteller „Siemens", Name „SIMATIC S7-1500 Firmware", Version „2.9". Der Firmware-Stand ist die Version – eine eigene Firmware-Verwaltung gibt es nicht und braucht es nicht. Ordnen Sie die Software der Asset-Gruppe zu.
        </div>
    </div>
</div>

> **Hinweis:** Die Einstufung **Isoliert** senkt das effektive Risiko jeder Schwachstelle auf dieser Gruppe – so wirkt ein abgeschottetes Netz in der Bewertung.

**Nicht patchbar?** Häufig lässt sich eine Steuerung nicht kurzfristig aktualisieren: Herstellerfreigabe, Abnahme, Stillstandszeiten. Dann dokumentieren Sie den Umgang statt des Patches:

* Tragen Sie die Schutzmaßnahme im Feld **Minderung** der Schwachstelle ein – etwa Netzsegmentierung, eine Firewall-Regel oder Zugriff nur über das Wartungs-VPN. Bei Schwachstellen aus dem **CSAF-Import** stellt jede neue Advisory-Version in diesem Feld einen eigenen Block voran; Ihr Eintrag rutscht dadurch nach unten, bleibt aber erhalten.
* Setzen Sie das Vorkommen auf dieser Asset-Gruppe auf **Risiko akzeptiert** und begründen Sie das im Notizfeld des Vorkommens.
* Betrifft die Lücke nur eine Funktion, die auf Ihren Geräten abgeschaltet ist, ist **Nicht betroffen** der richtige Status.

So bleibt der Befund sichtbar und nachweisbar, ohne die Liste mit unlösbaren Aufgaben zu füllen. Kommt das Update später doch, setzen Sie das Vorkommen auf **Behoben**. Die Statuswerte im Einzelnen erklärt das Kapitel [Schwachstellen verwalten](schwachstellen).

## Wie geht's weiter?

Nachdem Ihr Inventar steht, sollten Sie festlegen, wer Zugriff auf welche Daten hat:

<div class="manual-next-action">
    <div class="manual-next-action-label">Empfohlener nächster Schritt</div>
    <h3 class="manual-next-action-title">Team & Rollen organisieren</h3>
    <p class="manual-next-action-text">Erfahren Sie, wie Sie Nutzergruppen erstellen und den Zugriff auf Ihre Asset-Gruppen steuern.</p>
    <a href="team" class="manual-next-action-button">
        Zum Kapitel: Team & Rollen
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>
