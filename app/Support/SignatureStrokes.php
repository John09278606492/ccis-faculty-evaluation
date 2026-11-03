<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Turns the pen strokes from the signature pad into a compact SVG path. The browser only
 * ever sends numbers and the path is built here, so a stored signature cannot carry markup.
 */
class SignatureStrokes
{
    public const WIDTH = 1700;

    public const HEIGHT = 480;

    private const MAX_STROKES = 200;

    private const MAX_POINTS = 12000;

    /** Below this drawn extent, in screen pixels, the mark is a stray tap rather than a signature. */
    private const MIN_EXTENT = 24;

    /** Points closer than this add size to the stored path but nothing visible on A4. */
    private const MIN_STEP = 6;

    private const PATH_PATTERN = '/^[MQL0-9 ]+$/';

    /** The stored path for a pad's JSON state, or null when it is not a usable signature. */
    public static function pathFrom(?string $json): ?string
    {
        if (blank($json) || strlen($json) > 400000) {
            return null;
        }
        $strokes = self::parse(json_decode($json, true));
        if (! $strokes) {
            return null;
        }

        $points = array_merge(...$strokes);
        $minX = min(array_column($points, 0));
        $minY = min(array_column($points, 1));
        $width = max(array_column($points, 0)) - $minX;
        $height = max(array_column($points, 1)) - $minY;
        if (max($width, $height) < self::MIN_EXTENT) {
            return null;
        }

        $scale = min(self::WIDTH * 0.94 / max($width, 1), self::HEIGHT * 0.84 / max($height, 1));
        $offsetX = (self::WIDTH - $width * $scale) / 2;
        $offsetY = (self::HEIGHT - $height * $scale) / 2;

        $paths = [];
        foreach ($strokes as $stroke) {
            $mapped = array_map(fn ($point) => [
                (int) round(($point[0] - $minX) * $scale + $offsetX),
                (int) round(($point[1] - $minY) * $scale + $offsetY),
            ], $stroke);
            $paths[] = self::strokePath(self::thin($mapped));
        }

        return implode(' ', $paths);
    }

    public static function svg(?string $path, string $label = ''): HtmlString
    {
        if (blank($path) || ! preg_match(self::PATH_PATTERN, $path)) {
            return new HtmlString('');
        }

        return new HtmlString(sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" class="signature-image" role="img" aria-label="%s"><path d="%s" fill="none" stroke="#111" stroke-width="11" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            self::WIDTH,
            self::HEIGHT,
            e($label),
            $path,
        ));
    }

    /** @return array<int, array<int, array{0: float, 1: float}>>|null */
    private static function parse(mixed $strokes): ?array
    {
        if (! is_array($strokes) || ! $strokes || count($strokes) > self::MAX_STROKES) {
            return null;
        }

        $clean = [];
        $total = 0;
        foreach ($strokes as $stroke) {
            if (! is_array($stroke) || ! $stroke) {
                return null;
            }
            $total += count($stroke);
            if ($total > self::MAX_POINTS) {
                return null;
            }
            foreach ($stroke as $point) {
                if (! is_array($point) || ! is_numeric($point[0] ?? null) || ! is_numeric($point[1] ?? null)) {
                    return null;
                }
            }
            $clean[] = array_map(fn ($point) => [(float) $point[0], (float) $point[1]], array_values($stroke));
        }

        return $clean;
    }

    private static function thin(array $points): array
    {
        $kept = [$points[0]];
        foreach ($points as $point) {
            $last = $kept[array_key_last($kept)];
            if (hypot($point[0] - $last[0], $point[1] - $last[1]) >= self::MIN_STEP) {
                $kept[] = $point;
            }
        }
        $end = $points[array_key_last($points)];
        if ($kept[array_key_last($kept)] !== $end) {
            $kept[] = $end;
        }

        return $kept;
    }

    /** Smooths through midpoints, the same curve the pad draws on screen. */
    private static function strokePath(array $points): string
    {
        $path = "M{$points[0][0]} {$points[0][1]}";
        if (count($points) === 1) {
            return $path.' L'.($points[0][0] + 1)." {$points[0][1]}";
        }
        for ($i = 1, $count = count($points); $i < $count; $i++) {
            [$x, $y] = $points[$i - 1];
            $midX = (int) round(($x + $points[$i][0]) / 2);
            $midY = (int) round(($y + $points[$i][1]) / 2);
            $path .= "Q{$x} {$y} {$midX} {$midY}";
        }
        [$x, $y] = $points[array_key_last($points)];

        return $path."L{$x} {$y}";
    }
}
