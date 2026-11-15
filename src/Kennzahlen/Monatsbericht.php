<?php

namespace Ladewache\Kennzahlen;

use PDO;

/**
 * Monatsbericht „Zustand der Ladeinfrastruktur“.
 *
 * Wozu: Lokalredaktionen, Kommunen und Klimaschutzmanager fragen nach einer
 * Zahl für *ihren* Kreis. Der Bericht macht aus der Messreihe jeden Monat genau das: je Kreis, Land und
 * Betreiber, wie viel Ladepunkt-Zeit als gestört gemeldet war und welche
 * Ladeeinrichtungen eine Woche und länger komplett stillstanden.
 *
 * ## Grundlage und Gewichtung
 *
 * Allein die Angaben der Betreiber am Nationalen Zugangspunkt (Mobilithek),
 * mitgeschrieben als Tabelle `samples` – Schema siehe scan() und README.
 *
 * Gewichtet nach Zeit: Der Abrufer schreibt bei Änderung, sonst spätestens
 * alle vier Stunden. Jeder Zustand gilt bis zur nächsten Zeile, höchstens
 * MAX_HOLD Sekunden. Die übrige Zeit des Berichtszeitraums bleibt im Nenner,
 * zählt aber als unbekannt.
 *
 * ## Was „gestört“ und „Dauerstörung“ heißt
 *
 * - **Ausfallquote**: gestörte Ladepunkt-Zeit (out_of_service) geteilt durch
 *   die *bekannte* Ladepunkt-Zeit – derselbe Nenner wie @see OutageRate.
 * - **Dauerstörung**: Die Ladeeinrichtung war am Stück mindestens MIN_TAGE
 *   Tage komplett still – mindestens ein Punkt als gestört gemeldet, keiner
 *   frei, belegt oder vorgemerkt. Lücken in der Messreihe bis LUECKE Sekunden
 *   unterbrechen die Phase nicht; nur ein Lebenszeichen beendet sie. Gemessen:
 *   Einzelne Feeds liefern tagelang nichts (eine Station vom 04. bis
 *   07.10.2026, davor und danach „außer Betrieb“); mit einem Tag Toleranz zerfielen 350 echte
 *   Dauerstörungen in Stücke und der Oktober zeigte nur 60. Längere Lücken
 *   beenden die Phase, weil dann niemand mehr hingesehen hat.
 *
 * Gemessen wird, was die Betreiber melden, nicht was ist. Ein Betreiber, der
 * bei Störung schweigt, erscheint mit niedriger Quote und geringer Abdeckung.
 * Deshalb steht die Abdeckung immer neben der Quote.
 *
 * Empfehlung: Einen gerechneten Monat danach nicht mehr ändern. Eine zitierte
 * Zahl muss nächste Woche noch dastehen.
 */
final class Monatsbericht
{
    public const MAX_HOLD = 15300;

    /** Messlücken bis zu dieser Länge unterbrechen eine Dauerstörung nicht. */
    public const LUECKE = 7 * 86400;

    public const MIN_TAGE = 7;

    /** Unter so vielen gemessenen Ladeeinrichtungen bekommt ein Kreis keinen Rang. */
    public const MIN_KREIS = 30;

    /** Wie OutageRate::MIN_STATIONS – darunter entscheidet der Zufall die Rangliste. */
    public const MIN_BETREIBER = 200;

    public const MIN_ABDECKUNG = 0.5;

    /**
     * Eine Ladeeinrichtung über den Zeitraum aufsummieren.
     *
     * @param iterable<array<string, mixed>> $zeilen eine Station, nach ts_unix sortiert
     * @return array{sec: int, total: int, known: int, usable: int, busy: int, oos: int,
     *               tot_sec: int, lauf: int, lauf_ab: ?int, offen: bool}
     */
    public static function station(iterable $zeilen, int $von, int $bis): array
    {
        $s = ['sec' => 0, 'total' => 0, 'known' => 0, 'usable' => 0, 'busy' => 0, 'oos' => 0,
              'tot_sec' => 0, 'lauf' => 0, 'lauf_ab' => null, 'offen' => false];
        $vorher = null;
        $phaseAb = null;
        $phaseBis = null;

        $phaseZu = static function (int $ende) use (&$s, &$phaseAb): void {
            if ($phaseAb !== null && $ende - $phaseAb > $s['lauf']) {
                $s['lauf'] = $ende - $phaseAb;
                $s['lauf_ab'] = $phaseAb;
            }
        };

        foreach ($zeilen as $r) {
            $z = self::zeile($r);
            if ($vorher === null) {
                // Vor der ersten Meldung ist der Zustand unbekannt. Die erste
                // beobachtete Gesamtzahl dient als Nenner für diese Anlaufzeit.
                self::unbekannt($s, $z['total'], max(0, $z['ts'] - $von));
            } else {
                // Eine Messung vor Periodenbeginn darf in die Periode
                // hineinwirken, aber niemals Zeit davor verbuchen.
                $intervallAb = max($vorher['ts'], $von);
                $naechste = max(0, $z['ts'] - $intervallAb);
                $bekanntBis = min($z['ts'], $vorher['ts'] + self::MAX_HOLD);
                self::buchen($s, $vorher, $bekanntBis, $von);
                $bekanntDauer = max(0, $bekanntBis - $intervallAb);
                self::unbekannt($s, $vorher['total'], max(0, $naechste - $bekanntDauer));
            }

            if ($z['tot']) {
                if ($phaseAb === null || $z['ts'] - (int) $phaseBis > self::LUECKE) {
                    if ($phaseAb !== null) {
                        $phaseZu((int) $phaseBis);
                    }
                    $phaseAb = $z['ts'];
                }
                $phaseBis = $z['ts'];
            } elseif ($z['lebt'] && $phaseAb !== null) {
                // Ende der Phase ist der erste Beleg, dass sie wieder lief.
                $phaseZu($z['ts']);
                $phaseAb = null;
            }

            $vorher = $z;
        }

        if ($vorher !== null) {
            $ende = min($vorher['ts'] + self::MAX_HOLD, $bis);
            self::buchen($s, $vorher, $ende, $von);
            self::unbekannt($s, $vorher['total'], max(0, $bis - max($von, $vorher['ts'], $ende)));
            if ($phaseAb !== null) {
                // Noch still: Bis Zeitraumende, wenn die Reihe bis dahin läuft.
                $offen = $bis - (int) $phaseBis <= 2 * 86400;
                $phaseZu($offen ? $bis : (int) $phaseBis);
                $s['offen'] = $offen && $s['lauf_ab'] === $phaseAb;
            }
        }

        return $s;
    }

    /** @return array{ts: int, avl: int, occ: int, oos: int, total: int, known: int, tot: bool, lebt: bool} */
    private static function zeile(array $r): array
    {
        $total = max(0, (int) ($r['total'] ?? 0));
        $avl = max(0, (int) ($r['available'] ?? 0));
        $occ = max(0, (int) ($r['occupied'] ?? 0)) + max(0, (int) ($r['reserved'] ?? 0));
        $oos = max(0, (int) ($r['out_of_service'] ?? 0));
        $unknown = max(0, (int) ($r['unknown'] ?? 0));

        return [
            'ts' => (int) $r['ts_unix'],
            'avl' => $avl,
            'occ' => $occ,
            'oos' => $oos,
            'total' => $total,
            'known' => min($total, max(0, $total - $unknown)),
            'tot' => $oos > 0 && $avl === 0 && $occ === 0,
            'lebt' => $avl > 0 || $occ > 0,
        ];
    }

    /**
     * Erwartete Ladepunkt-Zeit ohne frische Zustandsmeldung verbuchen.
     */
    private static function unbekannt(array &$s, int $total, int $sekunden): void
    {
        if ($sekunden <= 0 || $total <= 0) {
            return;
        }

        $s['sec'] += $sekunden;
        $s['total'] += $sekunden * $total;
    }

    private static function buchen(array &$s, array $z, int $ende, int $von): void
    {
        $d = $ende - max($z['ts'], $von);
        if ($d <= 0) {
            return;
        }
        $nutzbar = max(0, $z['known'] - $z['oos']);
        $s['sec'] += $d;
        $s['total'] += $d * $z['total'];
        $s['known'] += $d * $z['known'];
        $s['usable'] += $d * $nutzbar;
        $s['busy'] += $d * min($z['occ'], $nutzbar);
        $s['oos'] += $d * min($z['oos'], $z['known']);
        $s['tot_sec'] += $z['tot'] ? $d : 0;
    }

    /**
     * Alle Ladeeinrichtungen eines Zeitraums aus der Messreihe.
     *
     * @return array{rows: int, stations: array<int, array<string, mixed>>}
     */
    public static function scan(PDO $samples, int $von, int $bis): array
    {
        $stmt = $samples->prepare(
            'SELECT station_id, ts_unix, available, occupied, reserved, out_of_service, unknown, total, id
               FROM samples
              WHERE ts_unix >= ? AND ts_unix < ? AND out_of_service IS NOT NULL
             UNION ALL
             SELECT prior.station_id, prior.ts_unix, prior.available, prior.occupied,
                    prior.reserved, prior.out_of_service, prior.unknown, prior.total, prior.id
               FROM samples AS prior
              WHERE prior.out_of_service IS NOT NULL
                AND prior.id = (
                    SELECT previous.id
                      FROM samples AS previous
                     WHERE previous.station_id = prior.station_id
                       AND previous.ts_unix < ?
                       AND previous.out_of_service IS NOT NULL
                     ORDER BY previous.ts_unix DESC, previous.id DESC
                     LIMIT 1
                )
              ORDER BY station_id, ts_unix, id'
        );
        // Die letzte Meldung vor Periodenbeginn ist nötig, um ihren gültigen
        // Anteil (höchstens MAX_HOLD) am Monatsanfang nicht zu verlieren.
        $stmt->execute([$von, $bis, $von]);

        $out = [];
        $rows = 0;
        $id = null;
        $puffer = [];
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows++;
            $sid = (int) $r['station_id'];
            if ($id !== null && $sid !== $id) {
                $out[$id] = self::station($puffer, $von, $bis);
                $puffer = [];
            }
            $id = $sid;
            $puffer[] = $r;
        }
        if ($id !== null) {
            $out[$id] = self::station($puffer, $von, $bis);
        }

        return ['rows' => $rows, 'stations' => $out];
    }

    /**
     * Aus den Stationssummen den Bericht bilden – ohne Datenbank, damit er sich prüfen lässt.
     *
     * @param array<int, array<string, mixed>> $stationen station_id => station()
     * @param array<int, array{ags?: ?string, land?: ?string, op?: ?string, kw?: float, klasse?: ?string, ort?: ?string, adresse?: ?string, ladepunkte?: ?int, aktiv_ab?: ?int}> $meta
     * @param array<string, array{name: string, kind: ?string, stations: int}> $kreise AGS => Name/Art/Bestand laut Register
     * @param array<string, string> $laender
     * @return array<string, mixed>
     */
    public static function auswerten(array $stationen, array $meta, array $kreise, array $laender, int $von, int $bis): array
    {
        $min = self::MIN_TAGE * 86400;
        $leer = static fn(): array => ['n' => 0, 'sec' => 0, 'total' => 0, 'known' => 0, 'usable' => 0,
            'busy' => 0, 'oos' => 0, 'dauer' => 0, 'dauer_offen' => 0, 'dc_n' => 0, 'dc_known' => 0, 'dc_oos' => 0];
        $bund = $leer();
        $jeKreis = [];
        $jeLand = [];
        $jeOp = [];
        $jeKlasse = [];
        $lang = [];

        // Das Register kann Ladeeinrichtungen enthalten, von denen im
        // Berichtszeitraum überhaupt keine Statusmeldung einging. Mit einer
        // bekannten Punktzahl gehören sie trotzdem in den Abdeckungsnenner.
        foreach ($meta as $id => $m) {
            if (isset($stationen[$id])) {
                continue;
            }

            $punkte = max(0, (int) ($m['ladepunkte'] ?? 0));
            $aktivAb = max($von, (int) ($m['aktiv_ab'] ?? $von));
            $sekunden = max(0, $bis - $aktivAb);
            if ($punkte <= 0 || $sekunden <= 0) {
                continue;
            }

            $stationen[$id] = [
                'sec' => $sekunden,
                'total' => $sekunden * $punkte,
                'known' => 0,
                'usable' => 0,
                'busy' => 0,
                'oos' => 0,
                'tot_sec' => 0,
                'lauf' => 0,
                'lauf_ab' => null,
                'offen' => false,
            ];
        }

        foreach ($stationen as $id => $s) {
            // Auch Stationen ohne frische Meldung gehören in den
            // Abdeckungsnenner; sonst verschwinden schweigende Feeds aus
            // der Statistik und lassen die Quote besser aussehen.
            if ($s['sec'] <= 0 || $s['total'] <= 0) {
                continue;
            }
            $m = $meta[$id] ?? ['ags' => null, 'land' => null, 'op' => null, 'kw' => 0.0, 'ort' => null, 'adresse' => null];
            $dauer = $s['lauf'] >= $min;
            $dc = (float) $m['kw'] >= 50;
            $klasse = (string) ($m['klasse'] ?? ($dc ? 'dc50' : 'ac'));

            $ziele = [&$bund];
            $ags = $m['ags'] !== null && $m['ags'] !== '' ? str_pad((string) $m['ags'], 5, '0', STR_PAD_LEFT) : null;
            if ($ags !== null) {
                $jeKreis[$ags] ??= $leer();
                $ziele[] = &$jeKreis[$ags];
            }
            if (($m['land'] ?? '') !== '') {
                $jeLand[$m['land']] ??= $leer();
                $ziele[] = &$jeLand[$m['land']];
            }
            $op = trim((string) $m['op']);
            if ($op !== '') {
                $jeOp[$op] ??= $leer();
                $ziele[] = &$jeOp[$op];
            }
            $jeKlasse[$klasse] ??= $leer();
            $ziele[] = &$jeKlasse[$klasse];

            foreach ($ziele as &$a) {
                $a['n']++;
                foreach (['sec', 'total', 'known', 'usable', 'busy', 'oos'] as $k) {
                    $a[$k] += $s[$k];
                }
                $a['dauer'] += $dauer ? 1 : 0;
                $a['dauer_offen'] += $dauer && $s['offen'] ? 1 : 0;
                if ($dc) {
                    $a['dc_n']++;
                    $a['dc_known'] += $s['known'];
                    $a['dc_oos'] += $s['oos'];
                }
            }
            unset($a, $ziele);

            if ($dauer) {
                $lang[] = [
                    'id' => (int) $id,
                    'tage' => round($s['lauf'] / 86400, 1),
                    'ab' => $s['lauf_ab'],
                    'offen' => $s['offen'],
                    'ags' => $ags,
                    'op' => $op,
                    'ort' => $m['ort'],
                    'adresse' => $m['adresse'],
                    'kw' => (float) $m['kw'],
                ];
            }
        }

        $kz = static function (array $a): array {
            return [
                'gemessen' => $a['n'],
                'ausfall' => $a['known'] > 0 ? round(100 * $a['oos'] / $a['known'], 2) : null,
                'ausfall_dc' => $a['dc_known'] > 0 ? round(100 * $a['dc_oos'] / $a['dc_known'], 2) : null,
                'dc' => $a['dc_n'],
                'belegt' => $a['usable'] > 0 ? round(100 * $a['busy'] / $a['usable'], 1) : null,
                'abdeckung' => $a['total'] > 0 ? round(100 * $a['known'] / $a['total'], 1) : null,
                'dauer' => $a['dauer'],
                'dauer_offen' => $a['dauer_offen'],
                'dauer_anteil' => $a['n'] > 0 ? round(100 * $a['dauer'] / $a['n'], 2) : null,
            ];
        };

        $kreisListe = [];
        foreach ($jeKreis as $ags => $a) {
            $k = $kz($a);
            $info = $kreise[$ags] ?? null;
            $kreisListe[$ags] = $k + [
                'ags' => $ags,
                'name' => $info['name'] ?? $ags,
                'art' => $info['kind'] ?? null,
                'land' => $laender[substr($ags, 0, 2)] ?? null,
                'bestand' => $info['stations'] ?? null,
            ];
        }

        // Rang nur, wo die Zahl trägt: genug gemessen und die Betreiber haben
        // mindestens die Hälfte der Zeit überhaupt etwas gemeldet.
        $rangbar = array_filter($kreisListe, static fn(array $k): bool
            => $k['gemessen'] >= self::MIN_KREIS && $k['ausfall'] !== null
               && ($k['abdeckung'] ?? 0) >= 100 * self::MIN_ABDECKUNG);
        uasort($rangbar, static fn(array $a, array $b): int
            => [$b['ausfall'], $b['gemessen']] <=> [$a['ausfall'], $a['gemessen']]);
        $platz = 0;
        foreach (array_keys($rangbar) as $ags) {
            $kreisListe[$ags]['rang'] = ++$platz;
        }
        $rangZahl = $platz;
        foreach ($kreisListe as &$k) {
            $k['rang'] ??= null;
            $k['raenge'] = $rangZahl;
        }
        unset($k);

        $landListe = [];
        foreach ($jeLand as $code => $a) {
            $landListe[$code] = $kz($a) + ['code' => (string) $code, 'name' => $laender[$code] ?? (string) $code];
        }
        uasort($landListe, static fn(array $a, array $b): int => ($b['ausfall'] ?? 0) <=> ($a['ausfall'] ?? 0));

        $opListe = [];
        $opUnter = 0;
        foreach ($jeOp as $name => $a) {
            if ($a['n'] < self::MIN_BETREIBER) {
                $opUnter++;
                continue;
            }
            $opListe[] = $kz($a) + ['name' => (string) $name, 'gewertet' => $a['total'] > 0 && $a['known'] / $a['total'] >= self::MIN_ABDECKUNG];
        }
        usort($opListe, static fn(array $a, array $b): int
            => [$b['gewertet'], $b['ausfall'] ?? 0] <=> [$a['gewertet'], $a['ausfall'] ?? 0]);

        $klassen = [];
        foreach (['ac', 'dc50', 'dc150', 'dc300'] as $kl) {
            if (isset($jeKlasse[$kl])) {
                $klassen[$kl] = $kz($jeKlasse[$kl]);
            }
        }

        usort($lang, static fn(array $a, array $b): int => [$b['tage'], $b['kw']] <=> [$a['tage'], $a['kw']]);

        return [
            'von' => $von,
            'bis' => $bis,
            'bund' => $kz($bund),
            'kreise' => $kreisListe,
            'laender' => $landListe,
            'betreiber' => $opListe,
            'betreiber_unter' => $opUnter,
            'klassen' => $klassen,
            'dauerstoerungen' => $lang,
        ];
    }

    /**
     * Erster und letzter Zeitpunkt eines Monats (Ortszeit Europe/Berlin).
     *
     * Mit $beginn lässt sich der Anfang anheben, etwa auf den ersten Tag, ab
     * dem die Messreihe vollständig ist.
     *
     * @return array{0: int, 1: int}
     */
    public static function zeitraum(string $monat, ?string $beginn = null): array
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $von = new \DateTimeImmutable($monat . '-01 00:00', $tz);
        $bis = $von->modify('first day of next month');
        if ($beginn !== null) {
            $von = max($von, new \DateTimeImmutable($beginn . ' 00:00', $tz));
        }

        return [$von->getTimestamp(), $bis->getTimestamp()];
    }
}
