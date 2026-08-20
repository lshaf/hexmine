<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Deterministic world generation, §5. Port of `frontend/src/game/worldgen.ts`.
 *
 * The map is ~5000x5000 = 25 million tiles and none of it is stored. Every tile
 * is a pure function of (col, row, seed), so the client derives identical
 * terrain without the server shipping a map. Only *mutations* -- depletion
 * timers and occupied slots -- ever reach a table.
 *
 * Any change here must be mirrored in the TypeScript copy, or client and server
 * will disagree about what is on a tile. HashParityTest guards the foundation;
 * WorldParityTest guards the derived output.
 */
final class WorldGen
{
    private const CENTER_COL = Balance::MAP_COLS / 2;
    private const CENTER_ROW = Balance::MAP_ROWS / 2;

    /** Fine cells per coherent region, and how strongly the region dominates. */
    private const COARSE_DOMINANCE = 0.6;

    /** Hexes are wider than tall; weighting keeps biome regions visually round. */
    private const ASPECT = 1.28;

    /** @var array<int,string> */
    private static array $biomeCache = [];

    /** @var array<int,array{x:int,y:int,biome:string}> */
    private static array $cellCache = [];

    private static function maxRadius(): float
    {
        return min(Balance::MAP_COLS, Balance::MAP_ROWS) / 2;
    }

    // --------------------------------------------------------------- rings

    /** Normalised distance from map centre, 0 at the capital ring, 1 at the rim. */
    public static function radiusOf(int $col, int $row): float
    {
        $dc = ($col - self::CENTER_COL) / self::maxRadius();
        $dr = ($row - self::CENTER_ROW) / self::maxRadius();

        return sqrt($dc * $dc + $dr * $dr);
    }

    /** §5.2 -- concentric rings drive generation, not just colour. */
    public static function ringOf(int $col, int $row): string
    {
        $r = self::radiusOf($col, $row);

        if ($r < Balance::RING_CENTER) {
            return 'center';
        }
        if ($r < Balance::RING_INNER) {
            return 'inner';
        }
        if ($r < Balance::RING_MID) {
            return 'mid';
        }

        return 'outer';
    }

    /** Contested tiles pay a risk premium in yield, §5.2. */
    public static function ringYield(string $ring): float
    {
        return match ($ring) {
            'inner' => 1.9,
            'mid' => 1.35,
            'center' => 0.0,
            default => 1.0,
        };
    }

    // -------------------------------------------------------------- biomes

    /**
     * §5.3 -- clustered regions, deliberately NOT noise. A jittered lattice
     * (one seed per cell, 5x5 neighbourhood search) rather than scattered seed
     * points: scattered seeds produced ~186-tile regions, far beyond a low-level
     * character's travel range, stranding players in a single biome.
     */
    private static function cellSeed(int $cx, int $cy): array
    {
        $cacheKey = $cy * 100000 + $cx;
        if (isset(self::$cellCache[$cacheKey])) {
            return self::$cellCache[$cacheKey];
        }

        $hx = Hash::hash2($cx, $cy, Balance::MAP_SEED ^ 0xb10e);
        $hy = Hash::hash2($cy, $cx, Balance::MAP_SEED ^ 0xb11e);
        $hMix = Hash::hash2($cx * 7 + $cy * 13, $cx - $cy, Balance::MAP_SEED ^ 0xb12e);

        $hCoarse = Hash::hash2(
            (int) floor($cx / Balance::BIOME_REGION_CELLS),
            (int) floor($cy / Balance::BIOME_REGION_CELLS),
            Balance::MAP_SEED ^ 0xc0a5,
        );
        $coarse = Catalog::BIOMES[Hash::randInt($hCoarse, 0, count(Catalog::BIOMES) - 1)];

        $biome = Hash::rand01($hMix) < self::COARSE_DOMINANCE
            ? $coarse
            : Catalog::BIOMES[Hash::randInt(
                Hash::hash2($cx, $cy, Balance::MAP_SEED ^ 0xd1a1),
                0,
                count(Catalog::BIOMES) - 1,
            )];

        $seed = [
            'x' => $cx * Balance::BIOME_CELL + Hash::randInt($hx, 0, Balance::BIOME_CELL - 1),
            'y' => $cy * Balance::BIOME_CELL + Hash::randInt($hy, 0, Balance::BIOME_CELL - 1),
            'biome' => $biome,
        ];

        if (count(self::$cellCache) > 40000) {
            self::$cellCache = [];
        }
        self::$cellCache[$cacheKey] = $seed;

        return $seed;
    }

    public static function biomeOf(int $col, int $row): string
    {
        $cacheKey = $row * Balance::MAP_COLS + $col;
        if (isset(self::$biomeCache[$cacheKey])) {
            return self::$biomeCache[$cacheKey];
        }

        $cx = (int) floor($col / Balance::BIOME_CELL);
        $cy = (int) floor($row / Balance::BIOME_CELL);

        $best = 'plains';
        $bestDistance = INF;

        for ($i = -2; $i <= 2; $i++) {
            for ($j = -2; $j <= 2; $j++) {
                $seed = self::cellSeed($cx + $i, $cy + $j);
                $dx = ($seed['x'] - $col) * self::ASPECT;
                $dy = $seed['y'] - $row;
                $distance = $dx * $dx + $dy * $dy;
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $seed['biome'];
                }
            }
        }

        if (count(self::$biomeCache) > 40000) {
            self::$biomeCache = [];
        }
        self::$biomeCache[$cacheKey] = $best;

        return $best;
    }

    // ---------------------------------------------------------- settlements

    /**
     * Settlements sit on a jittered lattice: one candidate site per cell gives
     * minimum spacing without storing anything. Cell size per tier is what
     * produces "villages > cities > capitals" in count -- §6 calls that a cost
     * curve outcome, and this is the generation half of it.
     */
    private const LATTICE = [
        'village' => ['cell' => 7, 'chance' => 0.55, 'salt' => 0x1111],
        'city' => ['cell' => 14, 'chance' => 0.45, 'salt' => 0x2222],
        'capital' => ['cell' => 26, 'chance' => 0.7, 'salt' => 0x3333],
    ];

    private const TIER_FOR_RING = [
        'outer' => 'village',
        'mid' => 'city',
        'inner' => null, // contested mining ground, no safe infrastructure
        'center' => 'capital',
    ];

    /** §6 -- village runs 1 of 5 lines, city 2, capital all 5. */
    private static function linesFor(string $tier, int $col, int $row): array
    {
        if ($tier === 'capital') {
            return Catalog::SKILLS;
        }

        $count = $tier === 'city' ? 2 : 1;
        $pool = Catalog::SKILLS;
        $picked = [];

        for ($i = 0; $i < $count; $i++) {
            $h = Hash::hash2($col, $row + $i * 977, Balance::MAP_SEED ^ 0x5171);
            $index = Hash::randInt($h, 0, count($pool) - 1);
            $picked[] = $pool[$index];
            array_splice($pool, $index, 1);
        }

        return $picked;
    }

    private static function nameFor(int $col, int $row, string $tier): string
    {
        $hp = Hash::hash2($col, $row, Balance::MAP_SEED ^ 0x7ae1);
        $hs = Hash::hash2($row, $col, Balance::MAP_SEED ^ 0x7ae2);

        $base = Catalog::NAME_PREFIXES[Hash::randInt($hp, 0, count(Catalog::NAME_PREFIXES) - 1)]
            .Catalog::NAME_SUFFIXES[Hash::randInt($hs, 0, count(Catalog::NAME_SUFFIXES) - 1)];

        return match ($tier) {
            'capital' => "{$base} Keep",
            'city' => "{$base} City",
            default => $base,
        };
    }

    /** The settlement on this tile, if any. Pure function of position. */
    public static function settlementAt(int $col, int $row): ?array
    {
        $ring = self::ringOf($col, $row);
        $tier = self::TIER_FOR_RING[$ring];
        if ($tier === null) {
            return null;
        }

        ['cell' => $cell, 'chance' => $chance, 'salt' => $salt] = self::LATTICE[$tier];
        $cellCol = (int) floor($col / $cell);
        $cellRow = (int) floor($row / $cell);

        $hc = Hash::hash2($cellCol, $cellRow, Balance::MAP_SEED ^ $salt);
        $hr = Hash::hash2($cellRow, $cellCol, Balance::MAP_SEED ^ ($salt + 1));
        $siteCol = $cellCol * $cell + Hash::randInt($hc, 0, $cell - 1);
        $siteRow = $cellRow * $cell + Hash::randInt($hr, 0, $cell - 1);

        if ($siteCol !== $col || $siteRow !== $row) {
            return null;
        }

        // Not every cell gets a settlement -- that is what makes density organic.
        $hp = Hash::hash2($cellCol, $cellRow, Balance::MAP_SEED ^ ($salt + 2));
        if (Hash::rand01($hp) > $chance) {
            return null;
        }

        // A site generated in one ring but landing in another is rejected, so
        // tiers never bleed across ring boundaries.
        if (self::ringOf($siteCol, $siteRow) !== $ring) {
            return null;
        }

        return [
            'id' => "s_{$col}_{$row}",
            'name' => self::nameFor($col, $row, $tier),
            'tier' => $tier,
            'col' => $col,
            'row' => $row,
            'lines' => self::linesFor($tier, $col, $row),
        ];
    }

    /** Parse a settlement id back to its tile and re-derive it. */
    public static function settlementById(string $id): ?array
    {
        if (! preg_match('/^s_(-?\d+)_(-?\d+)$/', $id, $m)) {
            return null;
        }

        return self::settlementAt((int) $m[1], (int) $m[2]);
    }

    // ------------------------------------------------------------- dungeons

    /**
     * §9.1 -- FIVE dungeons, one per biome, in the barren capital ring.
     *
     * Exactly five at fixed points. A per-tile probability roll was wrong: the
     * capital ring is ~125k tiles, so even a 3% chance produced thousands.
     */
    public static function dungeonSites(): array
    {
        static $sites = null;

        if ($sites !== null) {
            return $sites;
        }

        $sites = [];
        $count = count(Catalog::DUNGEONS);
        $radius = Balance::RING_CENTER * 0.62 * (min(Balance::MAP_COLS, Balance::MAP_ROWS) / 2);

        foreach (Catalog::DUNGEONS as $index => $dungeon) {
            $angle = (M_PI * 2 * $index) / $count - M_PI / 2;
            $sites[] = [
                'col' => (int) round(Balance::MAP_COLS / 2 + cos($angle) * $radius),
                'row' => (int) round(Balance::MAP_ROWS / 2 + sin($angle) * $radius),
                'dungeon' => $dungeon,
            ];
        }

        return $sites;
    }

    public static function dungeonAt(int $col, int $row): ?array
    {
        foreach (self::dungeonSites() as $site) {
            if ($site['col'] === $col && $site['row'] === $row) {
                return $site['dungeon'];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- tiles

    /**
     * §5.5 -- herd markers are temporary and time-bucketed, so they are
     * derivable rather than stored and every client agrees where they are.
     */
    private static function herdUntil(int $col, int $row, string $biome, int $now): ?int
    {
        if ($biome !== 'plains' && $biome !== 'grassland') {
            return null;
        }

        $lifetime = Balance::scaled(Balance::HERD_LIFETIME_MS);
        $bucket = intdiv($now, $lifetime);
        $h = Hash::hash2($col * 31 + $bucket, $row * 17 + $bucket, Balance::MAP_SEED ^ 0xbeef);

        if (Hash::rand01($h) > Balance::HERD_CHANCE) {
            return null;
        }

        return ($bucket + 1) * $lifetime;
    }

    /**
     * Build a tile. $mutation carries the only server-owned state a tile has;
     * everything else is derived.
     *
     * @param  array{slotsUsed?:int,regrowsAt?:int}  $mutation
     */
    public static function generateTile(int $col, int $row, int $now, array $mutation = []): array
    {
        $ring = self::ringOf($col, $row);
        $biome = self::biomeOf($col, $row);
        $settlement = self::settlementAt($col, $row);
        $dungeon = self::dungeonAt($col, $row);

        $hTime = Hash::hash2($col, $row, Balance::MAP_SEED ^ 0xa1);
        $hYield = Hash::hash2($col, $row, Balance::MAP_SEED ^ 0xb2);
        $hRare = Hash::hash2($col, $row, Balance::MAP_SEED ^ 0xc3);

        // §5.2 -- the capital ring is barren. That is the pressure that forces
        // traffic outward for materials and inward for processing.
        //
        // A depleted tile keeps its material: it is drained, not dead (§5.1), and
        // callers gate on regrowsAt. The UI needs the material to keep rendering
        // the right remnants while it regrows.
        $material = null;
        if ($ring !== 'center' && $settlement === null) {
            $material = ($ring === 'inner' && Hash::rand01($hRare) < Balance::RARE_SPAWN_CHANCE)
                ? Catalog::BIOME_RARE[$biome]
                : Catalog::BIOME_MATERIAL[$biome];
        }

        $regrowsAt = $mutation['regrowsAt'] ?? 0;

        return [
            'col' => $col,
            'row' => $row,
            'biome' => $biome,
            'ring' => $ring,
            'material' => $material,
            'baseSeconds' => Hash::randInt($hTime, Balance::MINING_BASE_MIN_SECONDS, Balance::MINING_BASE_MAX_SECONDS),
            'baseYield' => Hash::randInt($hYield, 3, 8),
            'slotsUsed' => $mutation['slotsUsed'] ?? 0,
            'regrowsAt' => $regrowsAt,
            'settlement' => $settlement,
            'dungeon' => $dungeon ? ['key' => $dungeon['key'], 'name' => $dungeon['name']] : null,
            'herdUntil' => self::herdUntil($col, $row, $biome, $now),
            'propSeed' => Hash::hash2($col, $row, Balance::MAP_SEED ^ 0xf00d),
        ];
    }
}
