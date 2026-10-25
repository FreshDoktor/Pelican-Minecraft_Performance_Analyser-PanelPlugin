<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Support;

/**
 * Points for the small SVG charts of the overview, drawn on the server with every update, so the page
 * needs no chart script.
 *
 * The values are steps: a heap value after GC holds until the next reading, a GC rate holds for the
 * span it was measured over.
 */
final class SvgChart
{
    /**
     * Polygon points of the band between two series, e.g. the old generation from 0 up to its value.
     *
     * @param  list<int>  $times
     * @param  list<int|float>  $lower
     * @param  list<int|float>  $upper
     */
    public static function band(array $times, array $lower, array $upper, float $yMax, int $width, int $height): string
    {
        if ($times === []) {
            return '';
        }

        $top = self::steps($times, $upper, $yMax, $width, $height);
        $bottom = array_reverse(self::steps($times, $lower, $yMax, $width, $height));

        return self::join([...$top, ...$bottom]);
    }

    /**
     * Polyline points of one series.
     *
     * @param  list<int>  $times
     * @param  list<int|float>  $values
     */
    public static function line(array $times, array $values, float $yMax, int $width, int $height): string
    {
        return $times === [] ? '' : self::join(self::steps($times, $values, $yMax, $width, $height));
    }

    /**
     * Y of a value, for a horizontal line like the maximum heap.
     */
    public static function y(int|float $value, float $yMax, int $height): float
    {
        if ($yMax <= 0) {
            return (float) $height;
        }

        return round($height - min(1.0, max(0.0, $value / $yMax)) * $height, 2);
    }

    /**
     * @param  list<int>  $times
     * @param  list<int|float>  $values
     * @return list<array{0: float, 1: float}>
     */
    protected static function steps(array $times, array $values, float $yMax, int $width, int $height): array
    {
        $first = $times[0];
        $span = $times[count($times) - 1] - $first;
        $points = [];

        foreach ($times as $i => $time) {
            $x = $span > 0 ? round(($time - $first) / $span * $width, 2) : 0.0;
            $y = self::y($values[$i] ?? 0, $yMax, $height);

            // Vertical step from the previous value to this one, then hold it.
            if ($points !== []) {
                $points[] = [$x, $points[count($points) - 1][1]];
            }

            $points[] = [$x, $y];
        }

        // A single reading, or the last one: hold it to the right edge.
        $points[] = [(float) $width, $points[count($points) - 1][1]];

        return $points;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     */
    protected static function join(array $points): string
    {
        return implode(' ', array_map(fn (array $point) => $point[0].','.$point[1], $points));
    }
}
