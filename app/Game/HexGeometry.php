<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Server-side hex maths, §13.2. Only the parts the API needs -- rendering
 * geometry stays on the client.
 *
 * Offset (odd-q) coordinates: flat-top hexes, odd columns pushed down half a
 * row. Port of the matching functions in `resources/js/map/hexGeometry.ts`.
 */
final class HexGeometry
{
    private const COL_STEP = 43.5;   // HEX_W * 0.75

    private const ROW_STEP = 34.0;   // HEX_H

    private const ODD_COL_OFFSET = 17.0;

    /** Hex distance in offset coords, via a cube-coordinate round trip. */
    public static function distance(int $aCol, int $aRow, int $bCol, int $bRow): int
    {
        [$ax, $ay, $az] = self::toCube($aCol, $aRow);
        [$bx, $by, $bz] = self::toCube($bCol, $bRow);

        return (int) max(abs($ax - $bx), abs($ay - $by), abs($az - $bz));
    }

    /**
     * Every hex a walker crosses from a to b, inclusive of both ends.
     *
     * Cube lerp then round, which is the standard hex line: it never skips a
     * hex and never doubles one back, so the count is always distance + 1. The
     * client draws the same road from the same endpoints, so a journey needs no
     * stored path -- only where it is going and when it started.
     *
     * @return array<int,array{col:int,row:int}>
     */
    public static function line(int $aCol, int $aRow, int $bCol, int $bRow): array
    {
        $steps = self::distance($aCol, $aRow, $bCol, $bRow);
        if ($steps === 0) {
            return [['col' => $aCol, 'row' => $aRow]];
        }

        [$ax, $ay, $az] = self::toCube($aCol, $aRow);
        [$bx, $by, $bz] = self::toCube($bCol, $bRow);

        $out = [];
        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            // The nudge keeps a midpoint that lands exactly between two hexes
            // from rounding differently on either end of the same line.
            $out[] = self::fromCube(self::roundCube(
                $ax + ($bx - $ax) * $t + 1e-6,
                $ay + ($by - $ay) * $t + 2e-6,
                $az + ($bz - $az) * $t - 3e-6,
            ));
        }

        return $out;
    }

    /** @return array{int,int,int} */
    private static function roundCube(float $x, float $y, float $z): array
    {
        $rx = (int) round($x);
        $ry = (int) round($y);
        $rz = (int) round($z);

        $dx = abs($rx - $x);
        $dy = abs($ry - $y);
        $dz = abs($rz - $z);

        if ($dx > $dy && $dx > $dz) {
            $rx = -$ry - $rz;
        } elseif ($dy > $dz) {
            $ry = -$rx - $rz;
        } else {
            $rz = -$rx - $ry;
        }

        return [$rx, $ry, $rz];
    }

    /**
     * @param  array{int,int,int}  $cube
     * @return array{col:int,row:int}
     */
    private static function fromCube(array $cube): array
    {
        [$x, , $z] = $cube;

        return ['col' => $x, 'row' => $z + intdiv($x - ($x & 1), 2)];
    }

    /** @return array{int,int,int} */
    private static function toCube(int $col, int $row): array
    {
        $x = $col;
        $z = $row - intdiv($col - ($col & 1), 2);

        return [$x, -$x - $z, $z];
    }

    /**
     * Tiles inside a viewport, plus margin so tall props are not clipped.
     *
     * @return array<int,array{col:int,row:int}>
     */
    public static function visibleTiles(int $centerCol, int $centerRow, float $width, float $height): array
    {
        $colRadius = (int) ceil($width / 2 / self::COL_STEP) + 2;
        $rowRadius = (int) ceil($height / 2 / self::ROW_STEP) + 3;

        $out = [];
        for ($col = $centerCol - $colRadius; $col <= $centerCol + $colRadius; $col++) {
            for ($row = $centerRow - $rowRadius; $row <= $centerRow + $rowRadius; $row++) {
                $out[] = ['col' => $col, 'row' => $row];
            }
        }

        return $out;
    }
}
