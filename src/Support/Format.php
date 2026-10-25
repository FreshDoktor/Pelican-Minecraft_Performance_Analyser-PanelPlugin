<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Support;

/**
 * Numbers for the page and the diagnosis. Plain PHP, so the diagnosis can be tested without Laravel.
 */
final class Format
{
    public const MIB = 1048576;

    public const GIB = 1073741824;

    /**
     * Binary units like the JVM uses them: -Xmx1907M are 1907 MiB.
     */
    public static function bytes(int|float|null $bytes): string
    {
        if ($bytes === null) {
            return '–';
        }

        if (abs($bytes) >= self::GIB) {
            return number_format($bytes / self::GIB, 2).' GiB';
        }

        if (abs($bytes) >= self::MIB) {
            return number_format($bytes / self::MIB).' MiB';
        }

        return number_format($bytes / 1024).' KiB';
    }

    public static function percent(float $percent, int $decimals = 1): string
    {
        return number_format($percent, $decimals).' %';
    }

    /**
     * A duration like 17 h 33 min, 4 min 12 s or 230 ms.
     */
    public static function duration(int|float|null $ms): string
    {
        if ($ms === null) {
            return '–';
        }

        if ($ms < 1000) {
            return number_format($ms).' ms';
        }

        $seconds = (int) floor($ms / 1000);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => "$days d $hours h",
            $hours > 0 => "$hours h $minutes min",
            $minutes > 0 => "$minutes min ".($seconds % 60).' s',
            default => number_format($ms / 1000, 1).' s',
        };
    }

    /**
     * CPUs like 1, 1.5 or 24.
     */
    public static function cpus(int|float|null $cpus): string
    {
        return $cpus === null ? '–' : rtrim(rtrim(number_format($cpus, 2), '0'), '.');
    }
}
