<?php

namespace Ladewache\Tests\Kennzahlen;

use Ladewache\Kennzahlen\Monatsbericht;
use PHPUnit\Framework\TestCase;

/**
 * Prüft die Rechnung hinter dem Monatsbericht (/bericht).
 *
 * Der Bericht nennt Kreise und Betreiber mit Platz. Festgehalten werden die
 * Entscheidungen, die das zulässig machen: Zeitgewichtung, was eine
 * Dauerstörung beendet und wer einen Platz bekommt.
 */
class MonatsberichtTest extends TestCase
{
    private const T0 = 1790800000;

    private static function z(int $ts, int $avl, int $occ, int $oos, int $total = 2, int $unknown = 0): array
    {
        return ['ts_unix' => $ts, 'available' => $avl, 'occupied' => $occ, 'reserved' => 0,
                'out_of_service' => $oos, 'unknown' => $unknown, 'total' => $total];
    }

    public function testGewichtetNachZeitNichtNachZeilen(): void
    {
        // Eine Stunde 1 von 2 Punkten gestört, dann vier Stunden-Zeilen ohne Störung.
        $h = 3600;
        $s = Monatsbericht::station([
            self::z(self::T0, 1, 0, 1),
            self::z(self::T0 + $h, 2, 0, 0),
            self::z(self::T0 + 2 * $h, 1, 1, 0),
        ], self::T0, self::T0 + 3 * $h);

        // Gestörte Punkt-Zeit: 1 Punkt × 1 h; bekannt: 2 Punkte × 3 h.
        $this->assertSame($h, $s['oos']);
        $this->assertSame(6 * $h, $s['known']);
    }

    public function testZeitNachAblaufDerMeldegueltigkeitZaehltAlsUnbekannt(): void
    {
        $h = 3600;
        $s = Monatsbericht::station([
            self::z(self::T0, 1, 0, 1),
        ], self::T0, self::T0 + 10 * $h);

        $this->assertSame(10 * $h, $s['sec']);
        $this->assertSame(2 * 10 * $h, $s['total']);
        $this->assertSame(2 * Monatsbericht::MAX_HOLD, $s['known']);
        $this->assertSame(Monatsbericht::MAX_HOLD, $s['oos']);
        $this->assertEqualsWithDelta(42.5, 100 * $s['known'] / $s['total'], 0.001);
    }

    public function testZeitVorDerErstenMeldungZaehltAlsUnbekannt(): void
    {
        $h = 3600;
        $s = Monatsbericht::station([
            self::z(self::T0 + 2 * $h, 2, 0, 0),
        ], self::T0, self::T0 + 3 * $h);

        $this->assertSame(3 * $h, $s['sec']);
        $this->assertSame(6 * $h, $s['total']);
        $this->assertSame(2 * $h, $s['known']);
        $this->assertEqualsWithDelta(33.333, 100 * $s['known'] / $s['total'], 0.001);
    }

    public function testStationenOhneBekannteZeitBleibenImAbdeckungsnenner(): void
    {
        $stationen = [
            1 => ['sec' => 100, 'total' => 100, 'known' => 0, 'usable' => 0, 'busy' => 0,
                  'oos' => 0, 'tot_sec' => 0, 'lauf' => 0, 'lauf_ab' => null, 'offen' => false],
            2 => ['sec' => 100, 'total' => 100, 'known' => 100, 'usable' => 100, 'busy' => 0,
                  'oos' => 0, 'tot_sec' => 0, 'lauf' => 0, 'lauf_ab' => null, 'offen' => false],
        ];
        $meta = [
            1 => ['ags' => '01001', 'land' => '01', 'op' => 'Schweigsam GmbH', 'kw' => 22.0],
            2 => ['ags' => '01001', 'land' => '01', 'op' => 'Schweigsam GmbH', 'kw' => 22.0],
        ];

        $bericht = Monatsbericht::auswerten($stationen, $meta, [], [], 0, 100);

        $this->assertSame(2, $bericht['bund']['gemessen']);
        $this->assertSame(50.0, $bericht['bund']['abdeckung']);
        $this->assertSame(50.0, $bericht['kreise']['01001']['abdeckung']);
    }

    public function testLetzteMeldungVorPeriodenbeginnWirktBisZumAblaufDerFrischefrist(): void
    {
        $h = 3600;
        $s = Monatsbericht::station([
            self::z(self::T0 - 2 * $h, 1, 0, 1),
            self::z(self::T0 + 3 * $h, 2, 0, 0),
        ], self::T0, self::T0 + 4 * $h);

        // Die alte Meldung gilt noch 2 h 15 min in den Monat hinein.
        // Danach sind 45 min unbekannt; die neue Meldung gilt die letzte Stunde.
        $this->assertSame(4 * $h, $s['sec']);
        $this->assertSame(8 * $h, $s['total']);
        $this->assertSame(6 * $h + 1800, $s['known']);
        $this->assertSame(2 * $h + 900, $s['oos']);
    }

    public function testAbgelaufeneMeldungVorPeriodenbeginnZaehltNichtAlsBekannt(): void
    {
        $h = 3600;
        $s = Monatsbericht::station([
            self::z(self::T0 - 10 * $h, 1, 0, 1),
        ], self::T0, self::T0 + 3 * $h);

        $this->assertSame(3 * $h, $s['sec']);
        $this->assertSame(6 * $h, $s['total']);
        $this->assertSame(0, $s['known']);
        $this->assertSame(0, $s['oos']);
    }

    public function testScanUebernimmtDieLetzteMeldungVorPeriodenbeginn(): void
    {
        $h = 3600;
        $pdo = new \\PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE samples (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            station_id INTEGER NOT NULL,
            ts_unix INTEGER NOT NULL,
            available INTEGER,
            occupied INTEGER,
            reserved INTEGER,
            out_of_service INTEGER,
            unknown INTEGER,
            total INTEGER
        )');

        $insert = $pdo->prepare(
            'INSERT INTO samples (station_id, ts_unix, available, occupied, reserved, out_of_service, unknown, total)
             VALUES (?, ?, ?, ?, 0, ?, 0, 2)'
        );
        $insert->execute([1, self::T0 - 2 * $h, 1, 0, 1]);
        $insert->execute([1, self::T0 + 3 * $h, 2, 0, 0]);
        // Station 2 hat im Berichtszeitraum keine neue Meldung mehr.
        $insert->execute([2, self::T0 - 10 * $h, 1, 0, 1]);

        $scan = Monatsbericht::scan($pdo, self::T0, self::T0 + 4 * $h);

        $this->assertSame(3, $scan['rows']);
        $this->assertSame(4 * $h, $scan['stations'][1]['sec']);
        $this->assertSame(6 * $h + 1800, $scan['stations'][1]['known']);
        $this->assertSame(8 * $h, $scan['stations'][1]['total']);
        $this->assertSame(0, $scan['stations'][2]['known']);
        $this->assertSame(8 * $h, $scan['stations'][2]['total']);
    }

    public function testMessluekeBeendetDauerstoerungNicht(): void
    {
        $tag = 86400;
        // Tot, drei Tage keine Meldung, weiter tot – zusammen zehn Tage.
        $zeilen = [];
        foreach ([0, 1, 2, 5, 6, 7, 8, 9, 10] as $d) {
            $zeilen[] = self::z(self::T0 + $d * $tag, 0, 0, 2);
        }
        $s = Monatsbericht::station($zeilen, self::T0, self::T0 + 11 * $tag);

        $this->assertSame(11 * $tag, $s['lauf']);
        $this->assertTrue($s['offen']);
    }

    public function testLebenszeichenBeendetDauerstoerung(): void
    {
        $tag = 86400;
        $zeilen = [];
        foreach (range(0, 8) as $d) {
            $zeilen[] = self::z(self::T0 + $d * $tag, 0, 0, 1);
        }
        // Tag 4: ein Punkt belegt – die Säule lief also.
        $zeilen[4] = self::z(self::T0 + 4 * $tag, 0, 1, 1);
        $s = Monatsbericht::station($zeilen, self::T0, self::T0 + 9 * $tag);

        $this->assertLessThan(Monatsbericht::MIN_TAGE * $tag, $s['lauf']);
    }

    public function testRangNurMitGenugMessungenUndAbdeckung(): void
    {
        $st = [];
        $meta = [];
        $id = 1;
        // Kreis A: 40 Säulen, 10 % gestört. Kreis B: 10 Säulen, 50 % gestört.
        // Kreis C: 40 Säulen, aber nur 30 % der Zeit gemeldet.
        foreach (['01001' => [40, 10, 100], '01002' => [10, 50, 100], '01003' => [40, 5, 30]] as $ags => [$n, $quote, $abd]) {
            for ($i = 0; $i < $n; $i++, $id++) {
                $st[$id] = ['sec' => 100, 'total' => 100, 'known' => $abd, 'usable' => $abd, 'busy' => 0,
                            'oos' => intdiv($abd * $quote, 100), 'tot_sec' => 0, 'lauf' => 0, 'lauf_ab' => null, 'offen' => false];
                $meta[$id] = ['ags' => $ags, 'land' => '01', 'op' => 'X', 'kw' => 22.0, 'klasse' => 'ac', 'ort' => null, 'adresse' => null];
            }
        }
        $r = Monatsbericht::auswerten($st, $meta, [], ['01' => 'Schleswig-Holstein'], 0, 100);

        $this->assertSame(1, $r['kreise']['01001']['rang']);
        $this->assertNull($r['kreise']['01002']['rang'], 'zu wenige Säulen');
        $this->assertNull($r['kreise']['01003']['rang'], 'zu wenig gemeldet');
        $this->assertSame(1, $r['kreise']['01001']['raenge']);
    }
}
