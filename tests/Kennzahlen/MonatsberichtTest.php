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
