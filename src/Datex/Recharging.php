<?php

namespace Ladewache\Datex;

/**
 * Übersetzt das AFIR-Profil von DATEX II in das, was hier gebraucht wird.
 *
 * Alle Angebote am nationalen Zugangspunkt tragen dasselbe Etikett – DATEX II
 * 3.6, Profil "AFIR Energy Infrastructure". Die Umsetzung ist trotzdem bei
 * jedem Anbieter anders, und ein Leser, der nur einen Weg kennt, findet bei
 * der Mehrzahl schlicht nichts. Die drei Stellen, an denen es auseinandergeht:
 *
 *  1. Die Koordinaten. chargecloud und Tesla schreiben sie unter
 *     locationReference.locPointLocation.coordinatesForDisplay, Qwello unter
 *     pointByCoordinates.pointCoordinates, eRound unter locAreaLocation – und
 *     eRound, Grid & Co und SMATRICS lassen locationReference am Standort ganz
 *     leer und führen die Position erst an der Station darunter. Deshalb wird
 *     hier der Reihe nach gesucht: Standort zuerst, Station als Rückfall.
 *
 *  2. Die EVSE-Kennung nach DIN SPEC 91286. chargecloud und Tesla schreiben sie
 *     direkt als idG des Ladepunkts, eRound eine undurchsichtige UUID – und die
 *     Kennung steckt dort unter externalIdentifier. Wer nur idG liest, hält
 *     eRound für kennungslos; tatsächlich sind es 20.035 Kennungen.
 *
 *  3. Der Preis. Dieselbe Geschichte: chargecloud, Grid & Co und SMATRICS
 *     melden ihn am Ladepunkt, Tesla an der Station. Und Grid & Co meldet
 *     netto mit Steuersatz, SMATRICS brutto, die beiden anderen sagen dazu
 *     nichts – siehe tariff().
 *
 *  4. Die Verpackung. Manche Angebote legen die Nutzlast direkt unter
 *     "payload", andere unter "messageContainer.payload", und dort mal als
 *     Objekt, mal als Liste.
 *
 * Der Bezug zwischen statischem und dynamischem Angebot läuft über die idG des
 * Ladepunkts, nicht über die EVSE-Kennung: Die idG hat jeder, die Kennung nicht.
 */
final class Recharging
{
    /**
     * Zustände, wie das Profil sie kennt, auf die vier eigenen abgebildet.
     *
     * Die Zielwerte sind dieselben, die AvailabilityStatus benutzt – damit ein
     * "frei" vom Zugangspunkt dasselbe bedeutet wie eines aus der Messreihe.
     *
     * 'removed' zählt bewusst als unbekannt und nicht als außer Betrieb: Der
     * Ladepunkt ist abgebaut, über den Standort sagt das nichts. Als Störung
     * gezählt würde er eine Serie auslösen, die nie endet.
     *
     * Aus demselben Grund (seit 15.11.2026) 'inoperative' und 'notavailable':
     * Nach DATEX II/AFIR heißt das „noch nicht oder nicht mehr in Betrieb“ –
     * eine Säule vor der Eröffnung oder nach der Stilllegung, keine Störung.
     * Eine Stichprobe von 40 Dauerstörungen zeigte, dass genau solche Punkte
     * wochenlang als „kaputt“ geführt wurden. Als Störung zählt nur, was der
     * Betreiber ausdrücklich so meldet: outOfOrder, faulted, outOfService.
     */
    private const STATUS_MAP = [
        'available' => 'available',
        'charging' => 'occupied',
        'occupied' => 'occupied',
        'inuse' => 'occupied',
        'blocked' => 'reserved',
        'reserved' => 'reserved',
        'outoforder' => 'outOfService',
        'outofservice' => 'outOfService',
        'faulted' => 'outOfService',
        'inoperative' => 'unknown',
        'notavailable' => 'unknown',
        'unknown' => 'unknown',
        'removed' => 'unknown',
        'planned' => 'unknown',
    ];

    /**
     * Die Ladepunkte eines Standorts aus dem statischen Abzug.
     *
     * @param array<string, mixed> $site
     * @return list<array{ref: string, evse: ?string, lat: float, lon: float, tariff: array<string, mixed>|null}>
     */
    public static function points(array $site): array
    {
        $siteCoord = self::coordinates($site['locationReference'] ?? null);
        $siteTariff = self::tariff($site);
        $points = [];

        foreach (self::listOf($site['energyInfrastructureStation'] ?? null) as $station) {
            if (!is_array($station)) {
                continue;
            }

            // Station vor Standort: Steht an der Station eine eigene Position,
            // ist sie die genauere – ein Standort kann mehrere Stationen über
            // einen Parkplatz verteilt haben.
            $coord = self::coordinates($station['locationReference'] ?? null) ?? $siteCoord;
            if ($coord === null) {
                continue;
            }

            // Auch der Preis steht je nach Anbieter auf einer anderen Ebene –
            // chargecloud, Grid & Co und SMATRICS am Ladepunkt, Tesla an der
            // Station. Gesucht wird deshalb von innen nach aussen: Was am
            // Ladepunkt steht, ist genauer als das, was fuer die ganze Station
            // gilt.
            $stationTariff = self::tariff($station) ?? $siteTariff;

            foreach (self::listOf($station['refillPoint'] ?? null) as $refill) {
                if (!is_array($refill)) {
                    continue;
                }

                $point = $refill['aegiElectricChargingPoint'] ?? $refill;
                if (!is_array($point)) {
                    continue;
                }

                $ref = $point['idG'] ?? null;
                if (!is_string($ref) || $ref === '') {
                    continue;
                }

                $points[] = [
                    'ref' => $ref,
                    'evse' => self::evseId($point),
                    'lat' => $coord[0],
                    'lon' => $coord[1],
                    'tariff' => self::tariff($point) ?? self::tariff($refill) ?? $stationTariff,
                ];
            }
        }

        return $points;
    }


    /**
     * Der veröffentlichte Ad-hoc-Preis eines Knotens.
     *
     * Wozu: Nach Artikel 20 AFIR gehört der Ad-hoc-Preis zu den Pflichtangaben
     * am nationalen Zugangspunkt, binnen 24 Stunden nach einer Änderung. Damit
     * hat eine Rechnungsprüfung erstmals einen Maßstab, der nicht vom Benutzer
     * kommt: nicht "weicht von dem ab, was du erwartet hast", sondern "weicht
     * von dem ab, was der Betreiber selbst gemeldet hat".
     *
     * Rückgabe: Arbeitspreis, Startgebühr, Blockiergebühr (mit Karenzzeit und
     * Deckel) sowie taxIncluded und taxRate, soweit gemeldet.
     *
     * Vier Regeln, alle in dieselbe Richtung – im Zweifel nichts:
     *
     *  1. Zeitlich begrenzte Preise zählen nicht. Tesla meldet neben 0,57 €/kWh
     *     zwei Nachtpreise von 0,50 €; die tragen ein overallPeriod. Der Preis
     *     ohne Zeitfenster ist der Grundpreis, und nur der ist hier gemeint.
     *     Gäbe es nur zeitlich begrenzte, käme nichts zurück.
     *
     *  2. Widersprechen sich zwei unbefristete Arbeitspreise, gilt keiner.
     *     Wer dann den ersten nimmt, rät – und ein geratener Preis erzeugt einen
     *     Vorwurf gegen einen Anbieter, der nichts falsch gemacht hat.
     *
     *  3. Ein Minutenpreis von 0 ist keine Blockiergebühr, sondern ihr
     *     Gegenteil. chargecloud meldet zu jedem Ladepunkt mehrere solcher
     *     Nullzeilen neben der eigentlichen Gebühr.
     *
     *  4. Ein Arbeitspreis, der erst ab einer bestimmten Minute gilt, ist ein
     *     Staffelpreis und nicht der, mit dem der Ladevorgang beginnt.
     *     Grid & Co staffelt so.
     *
     *  5. Ob der Preis brutto oder netto gemeint ist, wird nicht geraten.
     *     Grid & Co meldet ausdrücklich netto mit 19 % Steuersatz, SMATRICS
     *     ausdrücklich brutto, chargecloud und Tesla sagen gar nichts. Der
     *     Zustand wird deshalb mitgeführt und nicht aufgelöst; wer den Wert
     *     anzeigt, muss dazusagen, was er ist.
     *
     * @param array<string, mixed> $node Standort, Station oder Ladepunkt
     * @return array{perKwh: ?float, sessionFee: ?float, blockingPerMinute: ?float, blockingGrace: int, blockingCap: ?float, taxIncluded: ?bool, taxRate: ?float}|null
     */
    public static function tariff(array $node): ?array
    {
        $prices = self::energyPrices($node);

        if ($prices === []) {
            return null;
        }

        $perKwh = null;
        $perKwhWiderspruch = false;
        $sessionFee = null;
        $blocking = null;
        $grace = 0;
        $cap = null;
        $taxIncluded = null;
        $taxRate = null;
        $taxWiderspruch = false;

        foreach ($prices as $price) {
            if (!is_array($price)) {
                continue;
            }

            // Regel 1: Was nur zu bestimmten Zeiten gilt, ist nicht der
            // Grundpreis.
            if (isset($price['overallPeriod'])) {
                continue;
            }

            $type = $price['priceType']['value'] ?? null;
            $value = $price['value'] ?? null;

            if (!is_string($type) || !is_numeric($value)) {
                continue;
            }

            $value = (float) $value;

            switch ($type) {
                case 'pricePerKWh':
                    if ($value <= 0.0) {
                        break;
                    }

                    // Ein Arbeitspreis, der erst ab Minute 120 gilt, ist ein
                    // Staffelpreis und nicht der, mit dem der Ladevorgang
                    // beginnt. Grid & Co staffelt so.
                    $ab = $price['timeBasedApplicability']['fromMinute'] ?? null;
                    if (is_numeric($ab) && (int) $ab > 0) {
                        break;
                    }

                    if ($perKwh !== null && abs($perKwh - $value) > 0.0001) {
                        $perKwhWiderspruch = true; // Regel 2
                        break;
                    }

                    $perKwh = $value;

                    // Regel 4: Widersprechen sich zwei Angaben zur Steuer,
                    // gilt keine. Sonst entscheidet die Reihenfolge im
                    // Dokument darueber, ob 0,55 EUR netto oder brutto sind –
                    // ein Unterschied von 19 %.
                    if (isset($price['taxIncluded'])) {
                        $neu = (bool) $price['taxIncluded'];
                        $taxIncluded = ($taxIncluded !== null && $taxIncluded !== $neu) ? null : $neu;
                        $taxWiderspruch = $taxWiderspruch || ($taxIncluded === null);
                    }

                    if (is_numeric($price['taxRate'] ?? null)) {
                        $taxRate = (float) $price['taxRate'];
                    }
                    break;

                case 'flatRate':
                    if ($value > 0.0 && $sessionFee === null) {
                        $sessionFee = $value;
                    }
                    break;

                case 'pricePerMinute':
                    // Regel 3: Nullzeilen sind keine Gebuehr.
                    if ($value <= 0.0 || $blocking !== null) {
                        break;
                    }
                    $blocking = $value;
                    $von = $price['timeBasedApplicability']['fromMinute'] ?? null;
                    $grace = is_numeric($von) ? max(0, (int) $von) : 0;
                    $cap = is_numeric($price['priceCap'] ?? null) ? (float) $price['priceCap'] : null;
                    break;
            }
        }

        if ($perKwhWiderspruch) {
            $perKwh = null;
            $taxIncluded = null;
            $taxRate = null;
        } elseif ($taxWiderspruch) {
            $taxIncluded = null;
            $taxRate = null;
        }

        if ($perKwh === null && $sessionFee === null && $blocking === null) {
            return null;
        }

        return [
            'perKwh' => $perKwh,
            'sessionFee' => $sessionFee,
            'blockingPerMinute' => $blocking,
            'blockingGrace' => $grace,
            'blockingCap' => $cap,
            'taxIncluded' => $taxIncluded,
            'taxRate' => $taxRate,
        ];
    }

    /**
     * Die Preiszeilen unterhalb eines Knotens, ohne tiefer zu gehen als nötig.
     *
     * Bewusst kein freies Herumsuchen im ganzen Teilbaum: Sonst zöge der Preis
     * einer Station auch an einem Ladepunkt, der einen eigenen hat, und die
     * Reihenfolge in points() wäre wirkungslos.
     *
     * @return list<mixed>
     */
    private static function energyPrices(array $node): array
    {
        $out = [];

        foreach (self::listOf($node['electricEnergy'] ?? null) as $energy) {
            if (!is_array($energy)) {
                continue;
            }

            foreach (self::listOf($energy['energyRate'] ?? null) as $rate) {
                if (!is_array($rate)) {
                    continue;
                }

                foreach (self::listOf($rate['energyPrice'] ?? null) as $price) {
                    $out[] = $price;
                }
            }
        }

        return $out;
    }

    /**
     * Die Belegung aus einem dynamischen Abruf.
     *
     * Gibt zurück, was in dieser einen Nachricht stand – bei einem Vollbild der
     * Zustand aller Ladepunkte des Anbieters, bei einem Delta nur die zuletzt
     * gemeldeten Änderungen. Welches von beidem es ist, weiß diese Klasse
     * nicht und soll sie nicht wissen; das muss der Aufrufer je Angebot festlegen.
     *
     * @param array<string, mixed> $doc
     * @return array<string, string> idG des Ladepunkts => eigener Zustand
     */
    public static function statuses(array $doc): array
    {
        $out = [];

        foreach (self::payloads($doc) as $payload) {
            $pub = $payload['aegiEnergyInfrastructureStatusPublication'] ?? null;
            if (!is_array($pub)) {
                continue;
            }

            foreach (self::listOf($pub['energyInfrastructureSiteStatus'] ?? null) as $siteStatus) {
                if (!is_array($siteStatus)) {
                    continue;
                }

                foreach (self::listOf($siteStatus['energyInfrastructureStationStatus'] ?? null) as $stationStatus) {
                    if (!is_array($stationStatus)) {
                        continue;
                    }

                    foreach (self::listOf($stationStatus['refillPointStatus'] ?? null) as $refill) {
                        if (!is_array($refill)) {
                            continue;
                        }

                        $point = $refill['aegiElectricChargingPointStatus'] ?? $refill;
                        if (!is_array($point)) {
                            continue;
                        }

                        $ref = $point['reference']['idG'] ?? null;
                        $value = $point['status']['value'] ?? null;

                        if (!is_string($ref) || $ref === '' || !is_string($value)) {
                            continue;
                        }

                        $out[$ref] = self::STATUS_MAP[strtolower($value)] ?? 'unknown';
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Zeitpunkt der Veröffentlichung – der Lesezeiger des armen Mannes.
     *
     * Ohne Abonnement gibt es keinen echten Zeiger. Zwei Abrufe hintereinander
     * mit derselben publicationTime bedeuten aber sicher: Es ist nichts Neues
     * gekommen, die Nachricht ist schon verarbeitet. Das spart nicht den
     * Verlust dazwischen, aber immerhin das doppelte Schreiben.
     */
    public static function publicationTime(array $doc): ?string
    {
        foreach (self::payloads($doc) as $payload) {
            foreach (['aegiEnergyInfrastructureStatusPublication', 'aegiEnergyInfrastructureTablePublication'] as $key) {
                $time = $payload[$key]['publicationTime'] ?? null;
                if (is_string($time) && $time !== '') {
                    return $time;
                }
            }
        }

        return null;
    }

    /**
     * Die Nutzlasten eines Dokuments, egal wie verpackt.
     *
     * @param array<string, mixed> $doc
     * @return list<array<string, mixed>>
     */
    private static function payloads(array $doc): array
    {
        $raw = $doc['payload'] ?? $doc['messageContainer']['payload'] ?? null;

        if (!is_array($raw)) {
            return [];
        }

        // Eine Liste von Nutzlasten oder eine einzelne? Unterschieden am ersten
        // Schlüssel: bei einer Liste ist er numerisch.
        if (array_is_list($raw)) {
            return array_values(array_filter($raw, 'is_array'));
        }

        return [$raw];
    }

    /**
     * Die EVSE-Kennung eines Ladepunkts, wo immer sie steht.
     */
    private static function evseId(array $point): ?string
    {
        $direct = $point['idG'] ?? null;
        if (is_string($direct) && self::looksLikeEvseId($direct)) {
            return $direct;
        }

        $external = $point['externalIdentifier'] ?? null;
        foreach (self::listOf($external) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $identifier = $entry['identifier'] ?? null;
            if (!is_string($identifier) || $identifier === '') {
                continue;
            }

            $type = $entry['typeOfIdentifier']['extendedValueG'] ?? null;
            if ($type === 'evseId' || self::looksLikeEvseId($identifier)) {
                return $identifier;
            }
        }

        return null;
    }

    /**
     * Sieht das nach einer Kennung nach DIN SPEC 91286 aus?
     *
     * Land, Betreibercode, ein 'E' für die Ladeeinrichtung. Die Trennzeichen
     * sind optional – im Register stehen beide Schreibweisen nebeneinander.
     */
    private static function looksLikeEvseId(string $value): bool
    {
        return (bool) preg_match('/^[A-Z]{2}[\*\-]?[A-Za-z0-9]{3}[\*\-]?[Ee]/', $value);
    }

    /**
     * Koordinaten aus einer Ortsangabe, in der Reihenfolge der Genauigkeit.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function coordinates(mixed $reference): ?array
    {
        if (!is_array($reference)) {
            return null;
        }

        $candidates = [
            $reference['locPointLocation']['coordinatesForDisplay'] ?? null,
            $reference['locPointLocation']['pointByCoordinates']['pointCoordinates'] ?? null,
            $reference['locAreaLocation']['coordinatesForDisplay'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $lat = $candidate['latitude'] ?? null;
            $lon = $candidate['longitude'] ?? null;

            if (!is_numeric($lat) || !is_numeric($lon)) {
                continue;
            }

            $lat = (float) $lat;
            $lon = (float) $lon;

            // Ein Standort, der im Nullmeridian-Nirgendwo oder außerhalb der
            // Erde liegt, ist ein Meldefehler und keine Ladesäule. Der Abzug von
            // eRound enthält Punkte bis hinunter nach Südamerika.
            if ($lat === 0.0 && $lon === 0.0) {
                continue;
            }
            if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
                continue;
            }

            return [$lat, $lon];
        }

        return null;
    }

    /**
     * Ein Feld, das mal Liste und mal Einzelobjekt ist, als Liste.
     *
     * @return list<mixed>
     */
    private static function listOf(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }
}
