<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Deterministic 32-bit hashing.
 *
 * This is a bit-for-bit port of `frontend/src/game/worldgen.ts`. The client
 * generates the same 25-million-tile world locally so the server never has to
 * ship a map -- which only works while both sides agree on every bit.
 *
 * PHP integers are 64-bit, so every operation has to be masked back down to 32
 * and JavaScript's `Math.imul` / `>>>` semantics reproduced explicitly. Do not
 * "simplify" any of the masking below; see HashParityTest for the fixtures that
 * pin this to the JS implementation.
 */
final class Hash
{
    private const MASK = 0xFFFFFFFF;

    /**
     * JavaScript `Math.imul`: 32-bit integer multiply with wraparound.
     *
     * Split into 16-bit halves because a straight 32x32 multiply overflows into
     * the range where PHP would still be exact but the intermediate high bits
     * we do not want would survive the mask incorrectly.
     */
    public static function imul(int $a, int $b): int
    {
        $a &= self::MASK;
        $b &= self::MASK;

        $ah = ($a >> 16) & 0xFFFF;
        $al = $a & 0xFFFF;
        $bh = ($b >> 16) & 0xFFFF;
        $bl = $b & 0xFFFF;

        return ($al * $bl + ((($ah * $bl + $al * $bh) << 16) & self::MASK)) & self::MASK;
    }

    /**
     * Mixing hash of two coordinates. Values are kept unsigned throughout, so
     * PHP's `>>` is already the logical shift that JS spells `>>>`.
     */
    public static function hash2(int $x, int $y, int $seed): int
    {
        $h = ($seed ^ self::imul($x, 0x27d4eb2d) ^ self::imul($y, 0x165667b1)) & self::MASK;
        $h = self::imul($h ^ ($h >> 15), 0x2c1b3c6d);
        $h = self::imul($h ^ ($h >> 12), 0x297a2d39);

        return ($h ^ ($h >> 15)) & self::MASK;
    }

    /** Hash -> [0,1). Exact in IEEE754 on both sides: the divisor is 2^32. */
    public static function rand01(int $h): float
    {
        return $h / 4294967296.0;
    }

    /** Hash -> integer in [min,max]. */
    public static function randInt(int $h, int $min, int $max): int
    {
        return $min + (int) floor(self::rand01($h) * ($max - $min + 1));
    }
}
