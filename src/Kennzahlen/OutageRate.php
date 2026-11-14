<?php

namespace Ladewache\Kennzahlen;

/**
 * Wie viel Zeit die Ladepunkte eines Betreibers als "außer Betrieb" gemeldet sind.
 *
 * Wozu: Die Betreiber melden ihren Zustand seit AFIR selbst an den nationalen
 * Zugangspunkt, und wer diese Meldungen mitschreibt, hat alles Nötige. Damit
 * lässt sich eine Zahl bilden, die sonst niemand veröffentlicht – und zwar
 * ohne eine einzige Nutzermeldung und ohne einen einzigen Abrechnungsdatensatz:
 * Wie viel Prozent der Zeit sagt ein Betreiber über seine eigenen Ladepunkte,
 * dass sie nicht gehen.
 *
 * ## Der Nenner ist die Aussage
 *
 * Gerechnet wird gegen die **bekannte** Ladepunkt-Zeit, also ohne die Zeit, in
 * der gar keine frische Meldung vorlag (ein Ladepunkt ohne frische Meldung zählt
 * als `unknown`). Das ist keine Feinheit,
 * sondern der Unterschied zwischen Messung und Zufall: Am 12.09.2026 lagen bei
 * einem Feed 28 % der Ladepunkt-Zeit ohne Meldung, bei einem anderen 0,3 %.
 * Gegen die Gesamtzeit gerechnet stünde der schweigsame Betreiber automatisch
 * besser da.
 *
 * Deshalb kommt die Abdeckung (`coverage`) immer mit zurück und gehört neben
 * die Quote gestellt. Wer ein Drittel der Zeit nichts meldet, darf mit einer
 * niedrigen Ausfallquote nicht als vorbildlich dastehen.
 *
 * ## Was diese Zahl nicht kann
 *
 * Ein Ausfall kann sich hinter dem Schweigen verstecken: Ein Betreiber, dessen
 * Ladepunkt bei einer Störung einfach aufhört zu melden, produziert `unknown`
 * statt `outOfService`. Belegen lässt sich das nicht, widerlegen auch nicht.
 * Die Quote misst also, was gemeldet wird, und nicht, was ist – und genau so
 * ist sie zu beschriften.
 *
 * Eingabe sind Summen je Ladeeinrichtung (siehe aggregate()); woher sie
 * kommen, ist Sache des Aufrufers – z. B. Monatsbericht::station().
 */
final class OutageRate
{
    /**
     * Unter so vielen gemessenen Standorten wird ein Betreiber nicht genannt.
     *
     * Bewusst hoch. Der Wert ist gemessen und
     * nicht geschätzt: Über die beiden Hälften einer Woche verglichen, lag die
     * Rangkorrelation der Quoten ab 200 Standorten bei 0,94 und neun der zehn
     * Auffälligsten blieben dieselben; ab 50 Standorten waren es 0,88 und nur
     * fünf von zehn. Darunter macht ein einziger langer Ausfall die Zahl, und
     * eine Rangliste, die jede Woche anders aussieht, ist keine Auskunft über
     * den Betreiber, sondern über den Zufall.
     */
    public const MIN_STATIONS = 200;

    /**
     * So viel Ladepunkt-Zeit muss bekannt sein, sonst gilt die Quote nicht.
     *
     * Ein Betreiber, über dessen Ladepunkte die Hälfte der Zeit nichts vorliegt,
     * bekommt keine Quote, sondern den Hinweis auf die Lücke. Sonst entsteht
     * eine Zahl mit zwei Nachkommastellen aus einer Handvoll Messungen.
     */
    public const MIN_COVERAGE = 0.5;

    /**
     * Die Rechnung selbst, ohne Datenbank.
     *
     * Getrennt gehalten, weil hier die Entscheidungen stecken, die man prüfen
     * können muss: welcher Nenner gilt, wer genannt wird und wer nicht.
     *
     * @param list<array{station_id: int, samples: int, point_time: int,
     *                   unknown: int, out_of_service: int}> $stationen
     * @param array<int, string> $betreiber Stationskennung => Betreiber
     * @return array{days: int, market: array{stations: int, samples: int, point_time: int,
     *                                        known: int, outage: int, rate: ?float, coverage: ?float},
     *               operators: list<array{operator: string, stations: int, samples: int,
     *                                     known: int, outage: int, rate: float, coverage: float}>,
     *               below_threshold: int, without_coverage: int}
     */
    public static function aggregate(
        array $stationen,
        array $betreiber,
        int $days = 30,
        int $minStations = self::MIN_STATIONS,
        float $minCoverage = self::MIN_COVERAGE
    ): array {
        $markt = ['stations' => 0, 'samples' => 0, 'point_time' => 0, 'known' => 0, 'outage' => 0];
        $je = [];

        foreach ($stationen as $zeile) {
            // Bekannt ist, was gemeldet wurde. Negativ kann das nicht werden;
            // kaputte Zahlen aus der Quelle sollen die Summe nicht verschieben.
            $bekannt = max(0, $zeile['point_time'] - $zeile['unknown']);

            $markt['stations']++;
            $markt['samples'] += $zeile['samples'];
            $markt['point_time'] += $zeile['point_time'];
            $markt['known'] += $bekannt;
            $markt['outage'] += $zeile['out_of_service'];

            $name = trim($betreiber[$zeile['station_id']] ?? '');

            if ($name === '') {
                continue;
            }

            $je[$name] ??= ['stations' => 0, 'samples' => 0, 'point_time' => 0, 'known' => 0, 'outage' => 0];
            $je[$name]['stations']++;
            $je[$name]['samples'] += $zeile['samples'];
            $je[$name]['point_time'] += $zeile['point_time'];
            $je[$name]['known'] += $bekannt;
            $je[$name]['outage'] += $zeile['out_of_service'];
        }

        $out = [];
        $unterSchwelle = 0;
        $ohneAbdeckung = 0;

        foreach ($je as $name => $z) {
            if ($z['stations'] < $minStations) {
                $unterSchwelle++;
                continue;
            }

            $abdeckung = $z['point_time'] > 0 ? (float) $z['known'] / $z['point_time'] : 0.0;

            if ($z['known'] <= 0 || $abdeckung < $minCoverage) {
                $ohneAbdeckung++;
                continue;
            }

            $out[] = [
                'operator' => $name,
                'stations' => $z['stations'],
                'samples' => $z['samples'],
                'known' => $z['known'],
                'outage' => $z['outage'],
                'rate' => (float) $z['outage'] / $z['known'],
                'coverage' => $abdeckung,
            ];
        }

        // Die auffälligsten zuerst; bei gleicher Quote der größere Betreiber,
        // damit die Reihenfolge nicht zwischen zwei Läufen springt.
        usort($out, static fn(array $a, array $b): int
            => [$b['rate'], $b['stations']] <=> [$a['rate'], $a['stations']]);

        return [
            'days' => $days,
            'market' => $markt + [
                'rate' => $markt['known'] > 0 ? (float) $markt['outage'] / $markt['known'] : null,
                'coverage' => $markt['point_time'] > 0 ? (float) $markt['known'] / $markt['point_time'] : null,
            ],
            'operators' => $out,
            'below_threshold' => $unterSchwelle,
            'without_coverage' => $ohneAbdeckung,
        ];
    }
}
