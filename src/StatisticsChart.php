<?php

declare(strict_types=1);

namespace Schulverwaltung;

final class StatisticsChart
{
    public static function renderRing(string $title, array $series, array $colors, string $id): string
    {
        $safeId = self::escape($id);
        $svg = '<svg class="ring-chart" viewBox="0 0 200 200" role="img" aria-labelledby="'
            . $safeId . '-title ' . $safeId . '-description">';
        $svg .= '<title id="' . $safeId . '-title">' . self::escape($title) . '</title>';
        $svg .= '<desc id="' . $safeId . '-description">'
            . self::escape('Außen: Gesamt. Mitte: Warteliste. Innen: Bereinigt.')
            . '</desc>';

        $rings = [
            ['values' => $series['total'], 'radius' => 88],
            ['values' => $series['waitlist'], 'radius' => 64],
            ['values' => $series['adjusted'], 'radius' => 40],
        ];
        foreach ($rings as $ring) {
            $total = array_sum($ring['values']);
            if ($total === 0) {
                $svg .= '<circle cx="100" cy="100" r="' . $ring['radius']
                    . '" fill="none" stroke="#dce3e9" stroke-width="16"></circle>';
                continue;
            }

            $circumference = 2 * M_PI * $ring['radius'];
            $offset = 0.0;
            foreach ($ring['values'] as $key => $value) {
                if ($value === 0) {
                    continue;
                }

                $length = $circumference * $value / $total;
                $svg .= '<circle cx="100" cy="100" r="' . $ring['radius']
                    . '" fill="none" stroke="' . self::escape($colors[$key])
                    . '" stroke-width="16" stroke-dasharray="' . number_format($length, 2, '.', '')
                    . ' ' . number_format($circumference - $length, 2, '.', '')
                    . '" stroke-dashoffset="' . number_format(-$offset, 2, '.', '')
                    . '" transform="rotate(-90 100 100)"></circle>';
                $offset += $length;
            }
        }

        return $svg . '</svg>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
