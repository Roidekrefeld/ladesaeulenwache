<?php

namespace Ladewache\Tests\Kennzahlen;

use Ladewache\Kennzahlen\OutageRate;
use PHPUnit\Framework\TestCase;

/**
 * Prüft die Rechnung hinter der Ausfallquote.
 *
 * Die Quote nennt Betreiber beim Namen. Was hier festgehalten wird, sind
 * deshalb nicht die schönen Fälle, sondern die Entscheidungen, die sie
 * zulässig machen: gegen welche Zeit gerechnet wird, wer genannt wird und
 * wer trotz auffälliger Zahl nicht.
 */
class OutageRateTest extends TestCase
{
    /**
     * @param array<string, int> $werte
     * @return array{station_id: int, samples: int, point_time: int, unknown: int, out_of_service: int}
     */
    private function station(int $id, array $werte = []): array
    {
        return [
            'station_id' => $id,
            'samples' => $werte['samples'] ?? 100,
            'point_time' => $werte['point_time'] ?? 200,
            'unknown' => $werte['unknown'] ?? 0,
            'out_of_service' => $werte['out_of_service'] ?? 0,
        ];
    }

    /**
     * @param int $anzahl Stationen mit denselben Zahlen
     * @param array<string, int> $werte
     * @return list<array{station_id: int, samples: int, point_time: int, unknown: int, out_of_service: int}>
     */
    private function stationen(int $anzahl, array $werte = [], int $ab = 1): array
    {
        $out = [];
        for ($i = 0; $i < $anzahl; $i++) {
            $out[] = $this->station($ab + $i, $werte);
        }

        return $out;
    }

    /** @param list<array<string, int>> $stationen */
    private function alle(array $stationen, string $betreiber): array
    {
        return array_fill_keys(array_column($stationen, 'station_id'), $betreiber);
    }

    /**
     * Der Nenner ist die bekannte Zeit, nicht die gesamte.
     *
     * Zwei Betreiber mit derselben Ausfallzeit, aber verschiedener
     * Meldedisziplin: Gegen die Gesamtzeit gerechnet stünde der schweigsame
     * besser da, obwohl über ihn weniger bekannt ist.
     */
    public function test_the_rate_counts_only_time_that_was_reported(): void
    {
        $redselig = $this->stationen(200, ['point_time' => 200, 'unknown' => 0, 'out_of_service' => 10]);
        $schweigsam = $this->stationen(200, ['point_time' => 200, 'unknown' => 100, 'out_of_service' => 10], 1000);

        $ergebnis = OutageRate::aggregate(
            [...$redselig, ...$schweigsam],
            $this->alle($redselig, 'Redselig AG') + $this->alle($schweigsam, 'Schweigsam AG')
        );

        $quoten = array_column($ergebnis['operators'], null, 'operator');

        $this->assertSame(0.05, $quoten['Redselig AG']['rate']);
        $this->assertSame(0.1, $quoten['Schweigsam AG']['rate']);
        $this->assertSame(1.0, $quoten['Redselig AG']['coverage']);
        $this->assertSame(0.5, $quoten['Schweigsam AG']['coverage']);
    }

    /** Die Auffälligsten zuerst. */
    public function test_the_worst_rate_comes_first(): void
    {
        $gut = $this->stationen(200, ['out_of_service' => 2]);
        $schlecht = $this->stationen(200, ['out_of_service' => 20], 1000);

        $ergebnis = OutageRate::aggregate(
            [...$gut, ...$schlecht],
            $this->alle($gut, 'Gut GmbH') + $this->alle($schlecht, 'Schlecht GmbH')
        );

        $this->assertSame(['Schlecht GmbH', 'Gut GmbH'], array_column($ergebnis['operators'], 'operator'));
    }

    /** Zu klein für eine Tatsachenbehauptung – aber gezählt. */
    public function test_a_small_operator_is_not_named(): void
    {
        $klein = $this->stationen(199, ['out_of_service' => 100]);

        $ergebnis = OutageRate::aggregate($klein, $this->alle($klein, 'Klein GmbH'));

        $this->assertSame([], $ergebnis['operators']);
        $this->assertSame(1, $ergebnis['below_threshold']);
    }

    /** Wer überwiegend schweigt, bekommt keine Quote, sondern fällt heraus. */
    public function test_an_operator_without_enough_reports_gets_no_rate(): void
    {
        $still = $this->stationen(200, ['point_time' => 200, 'unknown' => 180, 'out_of_service' => 2]);

        $ergebnis = OutageRate::aggregate($still, $this->alle($still, 'Still GmbH'));

        $this->assertSame([], $ergebnis['operators']);
        $this->assertSame(1, $ergebnis['without_coverage']);
        $this->assertSame(0, $ergebnis['below_threshold']);
    }

    /**
     * Der Gesamtmarkt zählt alles Gemessene.
     *
     * Auch die Standorte ohne Betreibereintrag und die der kleinen Betreiber:
     * Die Marktzahl ist der Maßstab, gegen den eine Quote gelesen wird, und
     * dürfte sonst nur aus den Großen bestehen.
     */
    public function test_the_market_figure_includes_everyone(): void
    {
        $gross = $this->stationen(200, ['out_of_service' => 10]);
        $klein = $this->stationen(5, ['out_of_service' => 100], 1000);
        $namenlos = $this->stationen(5, ['out_of_service' => 0], 2000);

        $ergebnis = OutageRate::aggregate(
            [...$gross, ...$klein, ...$namenlos],
            $this->alle($gross, 'Gross AG') + $this->alle($klein, 'Klein GmbH')
        );

        $this->assertSame(210, $ergebnis['market']['stations']);
        $this->assertSame(42000, $ergebnis['market']['known']);
        $this->assertSame(2500, $ergebnis['market']['outage']);
        $this->assertEqualsWithDelta(0.0595, $ergebnis['market']['rate'], 0.0001);
        $this->assertSame(1.0, $ergebnis['market']['coverage']);
    }

    /** Ohne Messwerte gibt es keine Marktzahl, und schon gar keine Null. */
    public function test_without_data_there_is_no_rate(): void
    {
        $ergebnis = OutageRate::aggregate([], []);

        $this->assertNull($ergebnis['market']['rate']);
        $this->assertNull($ergebnis['market']['coverage']);
        $this->assertSame([], $ergebnis['operators']);
    }
}
