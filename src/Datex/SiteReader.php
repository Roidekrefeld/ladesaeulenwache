<?php

namespace Ladewache\Datex;

/**
 * Liest die Standorte aus einem statischen AFIR-Abzug, ohne ihn zu laden.
 *
 * Wozu: Die statischen Angebote sind groß – chargecloud 102 MB, eRound 126 MB
 * ausgepackt. Ein json_decode() darauf braucht ein Vielfaches davon an
 * Arbeitsspeicher, und auf einem kleinen Server ist danach Schluss. Gebraucht wird
 * aber nie das ganze Dokument, sondern immer nur ein Standort nach dem anderen.
 *
 * Deshalb dieser Leser: Er sucht im Datenstrom die Felder
 * "energyInfrastructureSite" und gibt deren Elemente einzeln heraus. Jedes
 * Element geht für sich durch json_decode(), der Speicherbedarf richtet sich
 * also nach dem größten Standort, nicht nach der Datei.
 *
 * Bewusst kein vollständiger JSON-Parser, sondern ein Klammerzähler: Er zählt
 * geschweifte Klammern und achtet dabei auf Zeichenketten und maskierte
 * Anführungszeichen. Mehr braucht es für diese Aufgabe nicht, und ein eigener
 * Parser wäre die deutlich größere Fehlerquelle. Die Prüfung, ob wirklich ein
 * gültiger Standort herausgekommen ist, übernimmt json_decode() selbst.
 *
 * Ein Dokument kann mehrere energyInfrastructureTable-Einträge mit je einer
 * eigenen Standortliste haben. Nach dem Ende einer Liste sucht der Leser
 * deshalb weiter und hört erst am Dateiende auf.
 */
final class SiteReader
{
    private const CHUNK = 262144; // 256 KB je Lesevorgang
    private const KEY = '"energyInfrastructureSite"';

    /** Ein einzelner Standort größer als das ist mit Sicherheit ein Fehler. */
    private const MAX_SITE_BYTES = 8388608; // 8 MB

    /**
     * Standorte eines Abzugs, einer nach dem anderen.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function sites(string $path): \Generator
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return;
        }

        try {
            $buffer = '';
            $eof = false;
            $inArray = false;

            while (true) {
                if (!$inArray) {
                    // Nächste Standortliste suchen.
                    $at = self::findKey($buffer);

                    if ($at === false) {
                        if ($eof) {
                            return;
                        }
                        // Den Schwanz behalten: der Schlüssel könnte genau auf
                        // der Grenze zweier Lesevorgänge liegen.
                        $keep = strlen(self::KEY);
                        $buffer = substr($buffer, max(0, strlen($buffer) - $keep));
                        [$buffer, $eof] = self::refill($fh, $buffer, $eof);
                        continue;
                    }

                    $buffer = substr($buffer, $at + strlen(self::KEY));

                    // Bis zur öffnenden Klammer vorspulen. Steht dort etwas
                    // anderes als '[', ist das Feld leer oder null – dann
                    // weitersuchen statt raten.
                    $open = self::skipToBracket($fh, $buffer, $eof);
                    if ($open === null) {
                        continue;
                    }
                    [$buffer, $eof, $found] = $open;
                    if (!$found) {
                        continue;
                    }

                    $inArray = true;
                    continue;
                }

                // In der Liste: Trennzeichen überspringen, Objekte einsammeln.
                $buffer = ltrim($buffer, " \t\r\n,");

                if ($buffer === '') {
                    if ($eof) {
                        return;
                    }
                    [$buffer, $eof] = self::refill($fh, $buffer, $eof);
                    continue;
                }

                if ($buffer[0] === ']') {
                    $buffer = substr($buffer, 1);
                    $inArray = false;
                    continue;
                }

                if ($buffer[0] !== '{') {
                    // Etwas Unerwartetes – die Liste hier abbrechen und die
                    // nächste suchen, statt den Rest der Datei zu verwerfen.
                    $inArray = false;
                    continue;
                }

                [$buffer, $eof, $raw] = self::readObject($fh, $buffer, $eof);

                // Kein vollständiges Objekt mehr: abgeschnittener Abzug oder
                // ein Standort jenseits der Reißleine. Was ganz da war, ist
                // heraus; der Rest ist nicht zu retten.
                if ($raw === null) {
                    return;
                }

                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * Nachschieben, bis der Puffer wieder etwas enthält.
     *
     * @param resource $fh
     * @return array{0: string, 1: bool}
     */
    private static function refill($fh, string $buffer, bool $eof): array
    {
        if ($eof) {
            return [$buffer, true];
        }

        $chunk = fread($fh, self::CHUNK);
        if ($chunk === false || $chunk === '') {
            return [$buffer, true];
        }

        return [$buffer . $chunk, feof($fh)];
    }

    /**
     * Findet den Feldnamen außerhalb von JSON-Zeichenketten.
     *
     * Ein einfacher strpos()-Treffer reicht nicht: Der Text eines anderen
     * Feldes kann den vollständigen Feldnamen samt scheinbarem Array enthalten.
     * In gültigem JSON sind die Anführungszeichen eines solchen Texttreffers
     * maskiert. Ein echter Feldname hat außerdem nach optionalem Leerraum
     * einen Doppelpunkt.
     */
    private static function findKey(string $buffer): int|false
    {
        $offset = 0;
        $length = strlen($buffer);
        $keyLength = strlen(self::KEY);

        while (($at = strpos($buffer, self::KEY, $offset)) !== false) {
            $slashes = 0;
            for ($i = $at - 1; $i >= 0 && $buffer[$i] === '\\\\'; $i--) {
                $slashes++;
            }

            $after = $at + $keyLength;
            while ($after < $length && str_contains(" \\t\\r\\n", $buffer[$after])) {
                $after++;
            }

            if (($slashes % 2) === 0 && $after < $length && $buffer[$after] === ':') {
                return $at;
            }

            $offset = $at + 1;
        }

        return false;
    }

    /**
     * Vom Doppelpunkt bis zur öffnenden eckigen Klammer vorspulen.
     *
     * @param resource $fh
     * @return array{0: string, 1: bool, 2: bool}|null
     */
    private static function skipToBracket($fh, string $buffer, bool $eof): ?array
    {
        $guard = 0;

        while (true) {
            $buffer = ltrim($buffer, " \t\r\n:");

            if ($buffer === '') {
                if ($eof) {
                    return null;
                }
                [$buffer, $eof] = self::refill($fh, $buffer, $eof);
                if (++$guard > 64) {
                    return null;
                }
                continue;
            }

            return [substr($buffer, $buffer[0] === '[' ? 1 : 0), $eof, $buffer[0] === '['];
        }
    }

    /**
     * Ein vollständiges Objekt ab Pufferanfang einsammeln.
     *
     * Zählt geschweifte Klammern und lässt dabei alles außer Acht, was in einer
     * Zeichenkette steht – sonst beendet eine Adresse mit einer Klammer im
     * Namen den Standort mitten im Satz.
     *
     * @param resource $fh
     * @return array{0: string, 1: bool, 2: string|null}
     */
    private static function readObject($fh, string $buffer, bool $eof): array
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $i = 0;

        while (true) {
            $len = strlen($buffer);

            for (; $i < $len; $i++) {
                $c = $buffer[$i];

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($c === '\\') {
                        $escaped = true;
                    } elseif ($c === '"') {
                        $inString = false;
                    }
                    continue;
                }

                if ($c === '"') {
                    $inString = true;
                } elseif ($c === '{') {
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $raw = substr($buffer, 0, $i + 1);
                        return [substr($buffer, $i + 1), $eof, $raw];
                    }
                }
            }

            if ($len > self::MAX_SITE_BYTES) {
                // Reißleine: Ohne sie liest ein beschädigter Abzug die ganze
                // Datei in den Speicher – genau das, was hier vermieden werden soll.
                return [$buffer, $eof, null];
            }

            if ($eof) {
                return [$buffer, true, null];
            }

            [$buffer, $eof] = self::refill($fh, $buffer, $eof);
        }
    }
}
