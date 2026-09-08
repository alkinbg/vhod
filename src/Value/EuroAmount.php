<?php

declare(strict_types=1);

namespace App\Value;

use InvalidArgumentException;

final class EuroAmount
{
    private function __construct()
    {
    }

    public static function parse(string $input): int
    {
        $normalized = str_replace(',', '.', trim($input));
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException('Invalid EUR amount.');
        }

        $whole = $matches[1];
        $fraction = $matches[2] ?? '';
        $fraction = str_pad($fraction, 2, '0');

        if (strlen($whole) > 16) {
            throw new InvalidArgumentException('EUR amount is too large.');
        }

        $cents = ((int) $whole * 100) + (int) $fraction;
        if ($cents <= 0) {
            throw new InvalidArgumentException('EUR amount must be positive.');
        }

        return $cents;
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }
}
