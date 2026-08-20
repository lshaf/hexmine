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
