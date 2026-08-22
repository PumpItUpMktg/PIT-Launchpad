<?php

namespace App\Client\Dashboard;

/**
 * Pure SVG geometry for the client dashboard's inline trend charts (§ Client Dashboard v1, PR 6b). Turns a
 * numeric series into polyline points / a closed area path in a fixed viewBox, so the Blade stays declarative
 * and the maths stays testable. No dependency on the data's meaning — just numbers in, coordinates out.
 */
final class Sparkline
{
    /**
     * Map values to "x,y x,y …" polyline points across [0,width] × [height,0], sharing an optional max so
     * several series plot on one axis. A single point sits at the right edge; an empty series yields ''.
     *
     * @param  list<int|float>  $values
     */
    public static function points(array $values, float $width = 620, float $height = 200, ?float $max = null, float $pad = 6): string
    {
        $n = count($values);
        if ($n === 0) {
            return '';
        }
        $max = $max ?? (float) max($values);
        $max = $max <= 0 ? 1.0 : $max;
        $usable = max(1.0, $height - $pad * 2);

        $pts = [];
        foreach ($values as $i => $v) {
            $x = $n === 1 ? $width : ($i / ($n - 1)) * $width;
            $y = $pad + ($usable - ((float) $v / $max) * $usable);
            $pts[] = round($x, 2).','.round($y, 2);
        }

        return implode(' ', $pts);
    }

    /**
     * The same series as a closed area path (down to the baseline and back), for a soft fill under the line.
     *
     * @param  list<int|float>  $values
     */
    public static function areaPath(array $values, float $width = 620, float $height = 200, ?float $max = null, float $pad = 6): string
    {
        $line = self::points($values, $width, $height, $max, $pad);
        if ($line === '') {
            return '';
        }
        $first = explode(' ', $line)[0];
        $firstX = explode(',', $first)[0];
        $lastX = $width;

        return 'M'.str_replace(' ', ' L', $line)." L{$lastX},{$height} L{$firstX},{$height} Z";
    }
}
