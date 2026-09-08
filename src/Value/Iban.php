<?php

declare(strict_types=1);

namespace App\Value;

use InvalidArgumentException;

final class Iban
{
    public static function normalize(string $iban): string
    {
        $normalized = preg_replace('/\s+/u', '', trim($iban));
        if (null === $normalized) {
            throw new InvalidArgumentException('Invalid IBAN.');
        }

        $normalized = strtoupper($normalized);
        $length = strlen($normalized);
        if ($length < 15 || $length > 34 || 1 !== preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/D', $normalized)) {
            throw new InvalidArgumentException('Invalid IBAN.');
        }

        $rearranged = substr($normalized, 4).substr($normalized, 0, 4);
        $remainder = 0;

        foreach (str_split($rearranged) as $character) {
            $numeric = ctype_digit($character) ? $character : (string) (ord($character) - 55);
            foreach (str_split($numeric) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        if (1 !== $remainder) {
            throw new InvalidArgumentException('Invalid IBAN checksum.');
        }

        return $normalized;
    }
}
