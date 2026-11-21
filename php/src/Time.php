<?php

declare(strict_types=1);

namespace ThePlay;

use InvalidArgumentException;

final class Time
{
    public static function seconds(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^(\d+):([0-5]\d):([0-5]\d)$/', $value, $match) === 1) {
            return ((int) $match[1] * 3600) + ((int) $match[2] * 60) + (int) $match[3];
        }
        if (preg_match('/^(\d+):([0-5]\d)$/', $value, $match) === 1) {
            return ((int) $match[1] * 60) + (int) $match[2];
        }
        throw new InvalidArgumentException('Use m:ss or h:mm:ss');
    }

    public static function assertRange(int $start, int $end): void
    {
        if ($end <= $start) {
            throw new InvalidArgumentException('End time must be after the start time');
        }
    }
}
