<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Protects CSV exports against formula injection: spreadsheet programs run cells starting with
 * = + - @ (or tab/CR) as formulas. Such text cells get a leading apostrophe; the importer removes it again.
 */
final class CsvSafe
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function cell(?string $value): ?string
    {
        if ($value === null || $value === '' || !\in_array($value[0], self::TRIGGERS, true)) {
            return $value;
        }

        return "'" . $value;
    }

    /**
     * Reverses {@see cell()} for values read from a CSV file.
     */
    public static function unescape(string $value): string
    {
        if (\strlen($value) > 1 && $value[0] === "'" && \in_array($value[1], self::TRIGGERS, true)) {
            return substr($value, 1);
        }

        return $value;
    }
}
