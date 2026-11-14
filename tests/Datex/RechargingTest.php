<?php

namespace Ladewache\Tests\Datex;

use Ladewache\Datex\Recharging;
use PHPUnit\Framework\TestCase;

/**
 * Die Eigenheiten der Anbieter am nationalen Zugangspunkt.
 *
 * Der Prüfgegenstand ist kein Randfall, sondern der Regelfall: Alle Angebote
 * tragen dasselbe Etikett – DATEX II 3.6, Profil "AFIR Energy Infrastructure" –
 * und legen die Koordinaten trotzdem an vier verschiedene Stellen. Ein Leser,
 * der nur einen Weg kennt, findet bei vier von sechs Anbietern null Standorte,
 * und zwar ohne Fehlermeldung. Genau das ist beim ersten Anlauf passiert.
 *
 * Die Formen hier sind nicht ausgedacht, sondern den Abzügen vom 03.09.2026
 * entnommen und nur auf das Nötige eingedampft.
 */
final class RechargingTest extends TestCase
{
    public function test_koordinaten_am_standort_wie_bei_chargecloud(): void
    {
        $site = [
            'locationReference' => [
                'locPointLocation' => [
                    'coordinatesForDisplay' => ['latitude' => 51.522918, 'longitude' => 9.930543],
                ],
            ],
            'energyInfrastructureStation' => [[
                'refillPoint' => [[
                    'aegiElectricChargingPoint' => ['idG' => 'DE*GOE*E00005*001'],
                ]],
            ]],
        ];

        $points = Recharging::points($site);

        self::assertCount(1, $points);
        self::assertSame('DE*GOE*E00005*001', $points[0]['ref']);
        self::assertSame('DE*GOE*E00005*001', $points[0]['evse']);
        self::assertSame(51.522918, $points[0]['lat']);
    }

    public function test_koordinaten_nur_an_der_station_wie_bei_eround(): void
    {
        // eRound, Grid & Co und SMATRICS lassen locationReference am Standort
        // leer. Wer dort aufgibt, verliert 6.713 Standorte allein bei eRound.
        $site = [
            'locationReference' => null,
            'energyInfrastructureStation' => [[
                'locationReference' => [
                    'locAreaLocation' => [
                        'coordinatesForDisplay' => ['latitude' => 51.9467315, 'longitude' => 9.4029231],
                    ],
                ],
                'refillPoint' => [[
                    'idG' => '16bc39bf627fba518275f6b6c238f17e',
                    'externalIdentifier' => [[
                        'identifier' => 'DE*WWL*E71101*04',
                        'typeOfIdentifier' => ['value' => 'extendedG', 'extendedValueG' => 'evseId'],
                    ]],
                ]],
            ]],
        ];

        $points = Recharging::points($site);

        self::assertCount(1, $points);
        self::assertSame(51.9467315, $points[0]['lat']);
    }

    public function test_die_evse_kennung_steht_bei_eround_unter_externalidentifier(): void
    {
        // Der Bezug zwischen statisch und dynamisch läuft über die idG, die
        // Verknüpfung mit dem Register über die Kennung. Wer nur idG liest,
        // hält eRound für kennungslos – tatsächlich sind es 20.035 Kennungen.
        $site = [
            'energyInfrastructureStation' => [[
                'locationReference' => [
                    'locPointLocation' => ['coordinatesForDisplay' => ['latitude' => 52.0, 'longitude' => 9.0]],
                ],
                'refillPoint' => [[
                    'idG' => '16bc39bf627fba518275f6b6c238f17e',
                    'externalIdentifier' => [[
                        'identifier' => 'DE*WWL*E71101*04',
                        'typeOfIdentifier' => ['extendedValueG' => 'evseId'],
                    ]],
                ]],
            ]],
        ];

        $points = Recharging::points($site);

        self::assertSame('16bc39bf627fba518275f6b6c238f17e', $points[0]['ref']);
        self::assertSame('DE*WWL*E71101*04', $points[0]['evse']);
    }

    public function test_koordinaten_unter_pointbycoordinates_wie_bei_qwello(): void
    {
        $site = [
            'locationReference' => [
                'locPointLocation' => [
                    'pointByCoordinates' => [
                        'pointCoordinates' => ['latitude' => 51.94766, 'longitude' => 7.61973],
                    ],
                ],
            ],
            'energyInfrastructureStation' => [[
                'refillPoint' => [['idG' => 'a39e418c-4db9-4cd9-88c6-8279e61ca68b']],
            ]],
        ];

        $points = Recharging::points($site);

        self::assertCount(1, $points);
        self::assertSame(7.61973, $points[0]['lon']);
        // Eine UUID ist keine EVSE-Kennung und darf auch nicht als eine
        // durchgehen – sonst sucht der Abgleich im Register nach Unsinn.
        self::assertNull($points[0]['evse']);
    }

    public function test_ein_standort_ohne_brauchbare_koordinaten_liefert_nichts(): void
    {
        // Im eRound-Abzug stehen Punkte bis hinunter nach Südamerika und
        // welche auf 0/0. Die als Ladesäule zu führen hiesse, sie irgendeinem
        // Registerstandort zuzuordnen – oder schlimmer, gar keinem und dafür
        // die Statistik zu verderben.
        $site = [
            'locationReference' => [
                'locPointLocation' => ['coordinatesForDisplay' => ['latitude' => 0, 'longitude' => 0]],
            ],
            'energyInfrastructureStation' => [[
                'refillPoint' => [['idG' => 'DE*XXX*E1']],
            ]],
        ];

        self::assertSame([], Recharging::points($site));
    }

    // ------------------------------------------------------------------
    // Der veröffentlichte Ad-hoc-Preis
    // ------------------------------------------------------------------

    /** @param list<array<string, mixed>> $preise */
    private function mitPreisen(array $preise): array
    {
        return ['electricEnergy' => [['energyRate' => [['energyPrice' => $preise]]]]];
    }

    public function test_der_volle_tarif_wie_chargecloud_ihn_meldet(): void
    {
        // Wortlaut aus dem Abzug vom 03.09.2026, samt der Nullzeilen, die
        // chargecloud zu jedem Ladepunkt mitschickt.
        $tarif = Recharging::tariff($this->mitPreisen([
            ['priceType' => ['value' => 'flatRate'], 'value' => 1.5],
            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.46],
            ['priceType' => ['value' => 'pricePerMinute'], 'value' => 0],
            ['priceType' => ['value' => 'pricePerMinute'], 'value' => 0],
            ['priceType' => ['value' => 'pricePerMinute'], 'value' => 0.06, 'timeBasedApplicability' => ['fromMinute' => 240]],
        ]));

        self::assertSame(0.46, $tarif['perKwh']);
        self::assertSame(1.5, $tarif['sessionFee']);
        // Eine Null ist keine Blockiergebühr, sondern ihr Gegenteil.
        self::assertSame(0.06, $tarif['blockingPerMinute']);
        self::assertSame(240, $tarif['blockingGrace']);
    }

    public function test_ein_zeitlich_begrenzter_preis_ist_nicht_der_grundpreis(): void
    {
        // Tesla meldet neben 0,57 zwei Nachtpreise von 0,50 mit overallPeriod.
        // Wer die mitnimmt, haelt zwei Preise fuer einen Widerspruch – oder
        // schlimmer, nimmt den Nachtpreis fuer den ganzen Tag.
        $tarif = Recharging::tariff($this->mitPreisen([
            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.57],
            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.5, 'overallPeriod' => ['overallStartTime' => '2026-07-01T22:00:00Z']],
        ]));

        self::assertSame(0.57, $tarif['perKwh']);
    }

    public function test_ein_staffelpreis_ab_minute_120_ist_nicht_der_einstiegspreis(): void
    {
        $tarif = Recharging::tariff($this->mitPreisen([
            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.55, 'timeBasedApplicability' => ['fromMinute' => 0, 'toMinute' => 120]],
            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.79, 'timeBasedApplicability' => ['fromMinute' => 120]],
        ]));

        self::assertSame(0.55, $tarif['perKwh']);
    }

    public function test_zwei_widersprechende_arbeitspreise_ergeben_keinen(): void
    {
        // Wer hier den ersten nimmt, raet – und ein geratener Preis erzeugt
        // einen Vorwurf gegen einen Anbieter, der nichts falsch gemacht hat.
        $tarif = Recharging::tariff($this->mitPreisen([
            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.46],
            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.79],
            ['priceType' => ['value' => 'flatRate'], 'value' => 1.0],
        ]));

        self::assertNull($tarif['perKwh']);
        // Die Startgebuehr bleibt, sie ist von dem Widerspruch nicht betroffen.
        self::assertSame(1.0, $tarif['sessionFee']);
    }

    public function test_die_steuerangabe_wird_mitgefuehrt_und_nicht_geraten(): void
    {
        $netto = Recharging::tariff($this->mitPreisen([
            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.55, 'taxIncluded' => false, 'taxRate' => 19.0],
        ]));
        self::assertFalse($netto['taxIncluded']);
        self::assertSame(19.0, $netto['taxRate']);

        $brutto = Recharging::tariff($this->mitPreisen([
            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.65, 'taxIncluded' => true],
        ]));
        self::assertTrue($brutto['taxIncluded']);

        // chargecloud und Tesla sagen dazu nichts – und dann wird nichts
        // behauptet. 19 % daneben zu liegen ist genau der Fehler, den die
        // ganze Klasse zu vermeiden versucht.
        $stumm = Recharging::tariff($this->mitPreisen([
            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.46],
        ]));
        self::assertNull($stumm['taxIncluded']);
    }

    public function test_widersprechende_steuerangaben_gelten_nicht(): void
    {
        // Sonst entscheidet die Reihenfolge im Dokument darueber, ob 0,55 EUR
        // netto oder brutto sind.
        $tarif = Recharging::tariff($this->mitPreisen([
            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.55, 'taxIncluded' => false, 'taxRate' => 19.0],
            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.55, 'taxIncluded' => true],
        ]));

        self::assertSame(0.55, $tarif['perKwh']);
        self::assertNull($tarif['taxIncluded']);
    }

    public function test_ohne_preisangabe_kommt_nichts(): void
    {
        self::assertNull(Recharging::tariff([]));
        self::assertNull(Recharging::tariff(['electricEnergy' => [['isGreenEnergy' => true]]]));
        // Nur Nullzeilen sind keine Preisangabe.
        self::assertNull(Recharging::tariff($this->mitPreisen([
            ['priceType' => ['value' => 'pricePerMinute'], 'value' => 0],
        ])));
    }

    public function test_der_preis_am_ladepunkt_schlaegt_den_der_station(): void
    {
        // chargecloud meldet am Ladepunkt, Tesla an der Station. Steht beides
        // da, ist das am Ladepunkt das genauere.
        $site = [
            'locationReference' => [
                'locPointLocation' => ['coordinatesForDisplay' => ['latitude' => 52.0, 'longitude' => 13.0]],
            ],
            'energyInfrastructureStation' => [[
                'electricEnergy' => [['energyRate' => [['energyPrice' => [
                    ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.39],
                ]]]]],
                'refillPoint' => [
                    ['aegiElectricChargingPoint' => [
                        'idG' => 'DE*ABC*E1',
                        'electricEnergy' => [['energyRate' => [['energyPrice' => [
                            ['priceType' => ['value' => 'pricePerKWh'], 'value' => 0.59],
                        ]]]]],
                    ]],
                    ['aegiElectricChargingPoint' => ['idG' => 'DE*ABC*E2']],
                ],
            ]],
        ];

        $points = Recharging::points($site);

        self::assertSame(0.59, $points[0]['tariff']['perKwh']);
        // Der zweite hat keinen eigenen und erbt den der Station.
        self::assertSame(0.39, $points[1]['tariff']['perKwh']);
    }

    public function test_zustaende_werden_auf_die_eigenen_vier_abgebildet(): void
    {
        $doc = ['payload' => ['aegiEnergyInfrastructureStatusPublication' => [
            'publicationTime' => '2026-09-02T22:34:06Z',
            'energyInfrastructureSiteStatus' => [[
                'energyInfrastructureStationStatus' => [[
                    'refillPointStatus' => [
                        ['aegiElectricChargingPointStatus' => [
                            'reference' => ['idG' => 'A'], 'status' => ['value' => 'available'],
                        ]],
                        ['aegiElectricChargingPointStatus' => [
                            'reference' => ['idG' => 'B'], 'status' => ['value' => 'charging'],
                        ]],
                        ['aegiElectricChargingPointStatus' => [
                            'reference' => ['idG' => 'C'], 'status' => ['value' => 'blocked'],
                        ]],
                        ['aegiElectricChargingPointStatus' => [
                            'reference' => ['idG' => 'D'], 'status' => ['value' => 'outOfOrder'],
                        ]],
                        ['aegiElectricChargingPointStatus' => [
                            'reference' => ['idG' => 'E'], 'status' => ['value' => 'removed'],
                        ]],
                    ],
                ]],
            ]],
        ]]];

        self::assertSame(
            [
                'A' => 'available',
                'B' => 'occupied',
                'C' => 'reserved',
                'D' => 'outOfService',
                // Abgebaut heisst nicht gestoert: Als Stoerung gezaehlt loeste
                // der Punkt eine Serie aus, die nie endet.
                'E' => 'unknown',
            ],
            Recharging::statuses($doc)
        );

        self::assertSame('2026-09-02T22:34:06Z', Recharging::publicationTime($doc));
    }

    public function test_die_nutzlast_darf_auch_verpackt_und_als_liste_kommen(): void
    {
        // Tesla verpackt in messageContainer und liefert die Nutzlast als Liste.
        $doc = ['messageContainer' => ['payload' => [[
            'aegiEnergyInfrastructureStatusPublication' => [
                'energyInfrastructureSiteStatus' => [[
                    'energyInfrastructureStationStatus' => [[
                        'refillPointStatus' => [[
                            'aegiElectricChargingPointStatus' => [
                                'reference' => ['idG' => 'DE*TSL*E0JAE6L'],
                                'status' => ['value' => 'occupied'],
                            ],
                        ]],
                    ]],
                ]],
            ],
        ]]]];

        self::assertSame(['DE*TSL*E0JAE6L' => 'occupied'], Recharging::statuses($doc));
    }

    public function test_ein_unbekannter_zustand_wird_unbekannt_und_nicht_verschluckt(): void
    {
        // Kommt ein Wert dazu, den das Profil heute nicht kennt, darf er nicht
        // stillschweigend als "frei" durchgehen.
        $doc = ['payload' => ['aegiEnergyInfrastructureStatusPublication' => [
            'energyInfrastructureSiteStatus' => [[
                'energyInfrastructureStationStatus' => [[
                    'refillPointStatus' => [[
                        'aegiElectricChargingPointStatus' => [
                            'reference' => ['idG' => 'X'], 'status' => ['value' => 'wasAuchImmer'],
                        ],
                    ]],
                ]],
            ]],
        ]]];

        self::assertSame(['X' => 'unknown'], Recharging::statuses($doc));
    }
}
