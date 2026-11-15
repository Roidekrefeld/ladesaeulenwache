<?php

namespace Ladewache\Tests\Datex;

use Ladewache\Datex\SiteReader;
use PHPUnit\Framework\TestCase;

/**
 * Der Klammerzähler, der die großen Abzüge lesbar macht.
 *
 * Der Prüfgegenstand ist ein Kompromiss: kein vollständiger JSON-Parser,
 * sondern ein Zähler für geschweifte Klammern. Das ist genau dann richtig, wenn
 * er die drei Fälle beherrscht, an denen ein naiver Zähler scheitert – eine
 * Klammer in einer Zeichenkette, ein maskiertes Anführungszeichen, und eine
 * Grenze zwischen zwei Lesevorgängen mitten im Objekt. Der dritte Fall ist der
 * gemeinste: Er tritt erst ab 256 KB auf, also nie in einem kleinen Testabzug,
 * aber immer im echten von 126 MB.
 */
final class SiteReaderTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function write(string $json): string
    {
        $this->path = tempnam(sys_get_temp_dir(), 'datex') ?: '';
        file_put_contents($this->path, $json);

        return $this->path;
    }

    public function test_die_standorte_kommen_einzeln_heraus(): void
    {
        $path = $this->write(json_encode(['payload' => [
            'aegiEnergyInfrastructureTablePublication' => [
                'energyInfrastructureTable' => [[
                    'energyInfrastructureSite' => [
                        ['idG' => 'A', 'name' => 'Erster'],
                        ['idG' => 'B', 'name' => 'Zweiter'],
                    ],
                ]],
            ],
        ]], JSON_THROW_ON_ERROR));

        $sites = iterator_to_array(SiteReader::sites($path), false);

        self::assertCount(2, $sites);
        self::assertSame('A', $sites[0]['idG']);
        self::assertSame('Zweiter', $sites[1]['name']);
    }

    public function test_eine_klammer_im_namen_beendet_den_standort_nicht(): void
    {
        // Klingt konstruiert, steht aber so in den Abzügen: Standortnamen
        // tragen Zusätze in Klammern, und Beschreibungen enthalten JSON-Reste.
        $path = $this->write(json_encode(['payload' => [
            'aegiEnergyInfrastructureTablePublication' => [
                'energyInfrastructureTable' => [[
                    'energyInfrastructureSite' => [
                        ['idG' => 'A', 'name' => 'Parkhaus {Mitte} "Nord" \\ Ebene 2', 'tief' => ['x' => ['y' => 1]]],
                        ['idG' => 'B'],
                    ],
                ]],
            ],
        ]], JSON_THROW_ON_ERROR));

        $sites = iterator_to_array(SiteReader::sites($path), false);

        self::assertCount(2, $sites);
        self::assertSame('Parkhaus {Mitte} "Nord" \\ Ebene 2', $sites[0]['name']);
        self::assertSame(1, $sites[0]['tief']['x']['y']);
        self::assertSame('B', $sites[1]['idG']);
    }

    public function test_ein_standort_ueber_mehrere_lesevorgaenge_hinweg(): void
    {
        // Der Leser holt 256 KB am Stück. Ein Standort, der größer ist als das,
        // muss über die Grenze hinweg zusammengesetzt werden – und die Grenze
        // liegt garantiert irgendwo mitten in einer Zeichenkette.
        $fett = str_repeat('Ladepunkt {mit} "Anführung" und \\ Schrägstrich. ', 12000);

        $path = $this->write(json_encode(['payload' => [
            'aegiEnergyInfrastructureTablePublication' => [
                'energyInfrastructureTable' => [[
                    'energyInfrastructureSite' => [
                        ['idG' => 'gross', 'text' => $fett],
                        ['idG' => 'danach'],
                    ],
                ]],
            ],
        ]], JSON_THROW_ON_ERROR));

        self::assertGreaterThan(262144, filesize($path));

        $sites = iterator_to_array(SiteReader::sites($path), false);

        self::assertCount(2, $sites);
        self::assertSame('gross', $sites[0]['idG']);
        self::assertSame($fett, $sites[0]['text']);
        self::assertSame('danach', $sites[1]['idG']);
    }

    public function test_mehrere_listen_in_einem_dokument(): void
    {
        // Das Profil erlaubt mehrere Tabellen je Veröffentlichung. Nach dem
        // Ende einer Liste muss weitergesucht werden statt aufzuhören.
        $path = $this->write(json_encode(['payload' => [
            'aegiEnergyInfrastructureTablePublication' => [
                'energyInfrastructureTable' => [
                    ['energyInfrastructureSite' => [['idG' => 'A']]],
                    ['energyInfrastructureSite' => [['idG' => 'B'], ['idG' => 'C']]],
                ],
            ],
        ]], JSON_THROW_ON_ERROR));

        $sites = iterator_to_array(SiteReader::sites($path), false);

        self::assertSame(['A', 'B', 'C'], array_column($sites, 'idG'));
    }

    public function test_eine_leere_liste_stoert_nicht(): void
    {
        $path = $this->write(json_encode(['payload' => [
            'aegiEnergyInfrastructureTablePublication' => [
                'energyInfrastructureTable' => [
                    ['energyInfrastructureSite' => []],
                    ['energyInfrastructureSite' => [['idG' => 'A']]],
                ],
            ],
        ]], JSON_THROW_ON_ERROR));

        $sites = iterator_to_array(SiteReader::sites($path), false);

        self::assertSame(['A'], array_column($sites, 'idG'));
    }

    public function test_ein_abgeschnittener_abzug_liefert_was_vollstaendig_war(): void
    {
        // Ein abgebrochener Abruf darf nicht in einer Endlosschleife oder mit
        // einem halben Standort enden. Was ganz da ist, zählt; der Rest fällt weg.
        $ganz = json_encode(['payload' => [
            'aegiEnergyInfrastructureTablePublication' => [
                'energyInfrastructureTable' => [[
                    'energyInfrastructureSite' => [['idG' => 'A'], ['idG' => 'B']],
                ]],
            ],
        ]], JSON_THROW_ON_ERROR);

        $path = $this->write(substr($ganz, 0, strpos($ganz, '"B"') ?: 0));

        $sites = iterator_to_array(SiteReader::sites($path), false);

        self::assertSame(['A'], array_column($sites, 'idG'));
    }

    public function test_feldname_in_einem_textfeld_wird_ignoriert(): void
    {
        $path = $this->write(json_encode([
            'payload' => [
                'description' => 'Beispiel: "energyInfrastructureSite": [{"idG":"FAKE"}]',
                'aegiEnergyInfrastructureTablePublication' => [
                    'energyInfrastructureTable' => [[
                        'energyInfrastructureSite' => [['idG' => 'ECHT']],
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $sites = iterator_to_array(SiteReader::sites($path), false);

        self::assertSame(['ECHT'], array_column($sites, 'idG'));
    }

    public function test_uebergrosser_standort_beendet_die_auswertung_begrenzt(): void
    {
        $json = '{"energyInfrastructureSite":['
            . json_encode(['idG' => 'vorher'], JSON_THROW_ON_ERROR) . ','
            . '{"idG":"zu_gross","text":"' . str_repeat('x', 8 * 1024 * 1024 + 1) . '"},'
            . json_encode(['idG' => 'danach'], JSON_THROW_ON_ERROR)
            . ']}';

        $path = $this->write($json);
        $sites = iterator_to_array(SiteReader::sites($path), false);

        self::assertSame(['vorher'], array_column($sites, 'idG'));
    }

    public function test_eine_fehlende_datei_liefert_nichts_statt_zu_scheitern(): void
    {
        self::assertSame([], iterator_to_array(SiteReader::sites('/gibt/es/nicht.json'), false));
    }
}
