# Offene Ladesäulen-Wache

Freie Software, die zeigt, wie zuverlässig öffentliche Ladesäulen wirklich sind.

Seit der EU-Verordnung **AFIR** melden die Betreiber den Live-Status jedes öffentlichen Ladepunkts an den Nationalen Zugangspunkt, in Deutschland die **[Mobilithek](https://mobilithek.info)**. Die Daten sind offen. Das Format (DATEX II) ist aber sperrig, und bisher macht kaum jemand unabhängig nachprüfbare Kennzahlen daraus.

Dieses Paket enthält den Kern, auf dem die Zustandsberichte von [chargemyride.live/bericht](https://chargemyride.live/bericht) beruhen:

| Teil | Was es tut |
|---|---|
| `Ladewache\Datex\SiteReader` | Liest statische AFIR-Abzüge Standort für Standort. Auch Dateien mit über 100 MB brauchen dafür kaum Arbeitsspeicher. |
| `Ladewache\Datex\Recharging` | Bildet die Ladepunkte, den Ad-hoc-Preis und den Live-Status ab. Die Angebote setzen das AFIR-Profil sehr unterschiedlich um (Koordinaten, EVSE-ID, Preisebene, Verpackung). Die Klasse berücksichtigt alle diese Varianten. |
| `Ladewache\Kennzahlen\Monatsbericht` | Gewichtet nach Zeit und berechnet Störungsquote, Meldeabdeckung, Belegung und Dauerstörungen (≥ 7 Tage) je Kreis, Land, Betreiber und Leistungsklasse. |
| `Ladewache\Kennzahlen\OutageRate` | Störungsquote je Betreiber, mit Mindestgröße und Mindestabdeckung. |

Der Stand ist **0.1**. Einlesen und Kennzahlen laufen in Betrieb und sind getestet. Ein fertiger Abrufer für die Mobilithek, das Kommunen-Paket (Bericht als Web/PDF, einbindbare Tabelle) und eine API sind geplant, siehe [Fahrplan](#fahrplan).

## Schnellstart

PHP 8.1 oder neuer, ohne weitere Abhängigkeiten.

```sh
git clone https://github.com/Roidekrefeld/ladesaeulenwache.git
cd ladesaeulenwache

# Ladepunkte eines statischen Abzugs als CSV
bin/ladepunkte statischer-abzug.json > ladepunkte.csv

# Live-Status eines dynamischen Abrufs als CSV
bin/status dynamischer-abruf.json > status.csv

# Monatsbericht aus einer Messreihe
bin/monatsbericht --db=messreihe.sqlite --monat=2026-10 --meta=stationen.csv > bericht.json
```

Mit Composer: `composer require chargemyride/ladesaeulen-wache` (sobald veröffentlicht) oder das Repository als Pfad einbinden. Tests: `phpunit`.

### Messreihe

`bin/monatsbericht` erwartet eine SQLite-Tabelle `samples`. Für jede Ladeeinrichtung steht darin eine Zeile, sobald sich der Zustand ändert, sonst spätestens alle vier Stunden:

```sql
CREATE TABLE samples (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  station_id     INTEGER NOT NULL,  -- eigene Kennung der Ladeeinrichtung
  ts_unix        INTEGER NOT NULL,
  weekday        INTEGER NOT NULL,
  hour           INTEGER NOT NULL,
  total          INTEGER,           -- Ladepunkte gesamt
  available      INTEGER,           -- frei
  occupied       INTEGER,           -- belegt
  reserved       INTEGER,           -- reserviert/blockiert
  out_of_service INTEGER,           -- als gestört gemeldet
  unknown        INTEGER            -- ohne frische Meldung
);

-- Beschleunigt die Suche nach der letzten Meldung je Ladeeinrichtung.
CREATE INDEX samples_station_time ON samples (station_id, ts_unix);
```

`stationen.csv` ordnet die Ladeeinrichtungen zu, getrennt mit Semikolon: `station_id;ags;land;betreiber;kw`. Dabei ist `ags` der 5-stellige Kreisschlüssel und `land` der 2-stellige Länderschlüssel.

## Methodik

- **Gemessen wird, was die Betreiber melden, nicht was vor Ort passiert.** Ein Betreiber, der bei einer Störung schweigt, erscheint mit niedriger Quote. Deshalb steht neben jeder Quote die **Meldeabdeckung**.
- **Störungsquote** = gestörte Ladepunkt-Zeit ÷ *bekannte* Ladepunkt-Zeit. Zeit ohne Meldung zählt nicht mit. Gegen die Gesamtzeit gerechnet stünde der schweigsame Betreiber besser da.
- **Gewichtung nach Zeit:** Jeder Zustand gilt bis zur nächsten Meldung, höchstens 4 h 15 min (`MAX_HOLD`). Danach gilt er als unbekannt. Die Meldeabdeckung teilt die bekannte Ladepunkt-Zeit durch die erwartete Ladepunkt-Zeit des gesamten Berichtszeitraums; Zeit vor der ersten Meldung und nach Ablauf von `MAX_HOLD` zählt als unbekannt. Vor der ersten Meldung wird die erste bekannte Gesamtzahl als Nenner angesetzt, da das Messschema kein Inbetriebnahmedatum enthält.
- **Dauerstörung:** Eine Ladeeinrichtung ist mindestens 7 Tage am Stück komplett still, das heißt mindestens ein Punkt ist als gestört gemeldet und keiner ist frei, belegt oder reserviert. Messlücken bis 7 Tage beenden die Phase nicht. Sie endet erst mit einem Lebenszeichen.
- **Ränge** gibt es nur, wenn die Zahl trägt. Ein Kreis braucht mindestens 30 gemessene Ladeeinrichtungen, ein Betreiber mindestens 200. In beiden Fällen muss für mindestens 50 % der Zeit eine Meldung vorliegen. Die Schwelle für Betreiber ist gemessen: Ab 200 Standorten blieb die Rangfolge zwischen zwei Wochenhälften stabil, die Rangkorrelation lag bei 0,94.
- `removed` und `planned` zählen als unbekannt, nicht als gestört.

Jede dieser Regeln steht im Code an der Stelle, an der sie greift, und ist durch Tests abgesichert.

## Fahrplan

1. Abrufer für die Mobilithek-Angebote (statisch und dynamisch, Voll- und Deltabild) mit SQLite-Messreihe
2. Zuordnung der Standorte zu Kreisen und Gemeinden
3. Kommunen-Paket: Monatsbericht je Kreis als Webseite und PDF, einbindbare Tabelle
4. Export (CSV/JSON) und einfache HTTP-Schnittstelle
5. Methodik-Papier in einfacher Sprache

## Lizenz

- Code: **[EUPL-1.2](LICENSE)**. Die Lizenz ist mit der GPL, AGPL und weiteren Copyleft-Lizenzen vereinbar.
- Dokumentation und berechnete Kennzahlen: **CC BY 4.0**, Quellenangabe „chargemyride.live“ bzw. „Offene Ladesäulen-Wache“.
- Die Daten der Mobilithek unterliegen den Lizenzen der jeweiligen Angebote. Dieses Repository enthält keine Rohdaten.

## Kontakt

Oliver Kloecker · [chargemyride.live](https://chargemyride.live) · info@chargemyride.live
