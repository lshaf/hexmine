<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Deterministic world generation, §5. Port of `frontend/src/game/worldgen.ts`.
 *
 * The map is square, Balance::mapSize() a side, and none of it is stored. Every tile
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
    /** §5.1 -- the middle of the world is the origin, and always was. */
    private const CENTER_COL = 0;

    private const CENTER_ROW = 0;

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
        return Balance::mapRadius();
    }

    // --------------------------------------------------------------- rings

    /** Normalised distance from map center, 0 at the capital ring, 1 at the rim. */
    /**
     * Forget everything derived from the map settings.
     *
     * The world is a pure function of (col, row, seed) and none of it is
     * stored, which is exactly why the caches here are safe -- right up until
     * the seed or the radius changes underneath them. A test that reaches into
     * config/game.php calls this; nothing in normal operation needs it.
     */
    public static function forget(): void
    {
        self::$biomeCache = [];
        self::$cellCache = [];
        Balance::forgetMapConfig();
    }

    /**
     * §5.1 -- is this hex on the map at all?
     *
     * The one place the edge is decided. Travel, mining and the client's render
     * loop all ask here rather than each spelling out the comparison, because
     * three copies of an inclusive bound is three chances to be off by one.
     */
    public static function inBounds(int $col, int $row): bool
    {
        $radius = Balance::mapRadius();

        return abs($col) <= $radius && abs($row) <= $radius;
    }

    public static function radiusOf(int $col, int $row): float
    {
        $dc = ($col - self::CENTER_COL) / self::maxRadius();
        $dr = ($row - self::CENTER_ROW) / self::maxRadius();

        return sqrt($dc * $dc + $dr * $dr);
    }

    /** §5.2 -- concentric rings drive generation, not just color. */
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
            // The center is the contested ring's own ground, not a hole in the
            // map: it was 0.0 while §5.2 kept it barren of everything, and a
            // seam that pays nothing would be a worse lie than no seam at all.
            'center', 'inner' => 1.9,
            'mid' => 1.35,
            default => 1.0,
        };
    }

    // -------------------------------------------------------------- biomes

    /**
     * §5.3 -- clustered regions, deliberately NOT noise. A jittered lattice
     * (one seed per cell, 5x5 neighborhood search) rather than scattered seed
     * points: scattered seeds produced ~186-tile regions, far beyond a low-level
     * character's travel range, stranding players in a single biome.
     */
    private static function cellSeed(int $cx, int $cy): array
    {
        $cacheKey = $cy * 100000 + $cx;
        if (isset(self::$cellCache[$cacheKey])) {
            return self::$cellCache[$cacheKey];
        }

        $hx = Hash::hash2($cx, $cy, Balance::mapSeed() ^ 0xB10E);
        $hy = Hash::hash2($cy, $cx, Balance::mapSeed() ^ 0xB11E);
        $hMix = Hash::hash2($cx * 7 + $cy * 13, $cx - $cy, Balance::mapSeed() ^ 0xB12E);

        $hCoarse = Hash::hash2(
            (int) floor($cx / Balance::BIOME_REGION_CELLS),
            (int) floor($cy / Balance::BIOME_REGION_CELLS),
            Balance::mapSeed() ^ 0xC0A5,
        );
        $coarse = Catalog::BIOMES[Hash::randInt($hCoarse, 0, count(Catalog::BIOMES) - 1)];

        $biome = Hash::rand01($hMix) < self::COARSE_DOMINANCE
            ? $coarse
            : Catalog::BIOMES[Hash::randInt(
                Hash::hash2($cx, $cy, Balance::mapSeed() ^ 0xD1A1),
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
        $cacheKey = ($row + Balance::mapRadius()) * Balance::mapSize()
            + ($col + Balance::mapRadius());
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

    // ----------------------------------------------------------------- water

    /**
     * §5.3 -- lakes and waterways, and neither is stored.
     *
     * Water is a pure function of (col, row, seed) like everything else on the
     * map, which is what lets the client draw a coastline the server never
     * ships. Both shapes are built out of integer hashes and straight
     * arithmetic: no sine, no cosine, because a boundary test that lands within
     * an ulp of the edge would flip a hex between water and land depending on
     * whose libm answered, and PHP and JS have to agree on every tile.
     */
    // --------------------------------------------------------- dead ground

    /**
     * §5.2 -- smooth noise in [0,1], the field dead ground is cut out of.
     *
     * Value noise on a coarse lattice, smoothstepped between corners, which is
     * the cheapest thing that clusters. It has to cluster: §5.3 argues biomes
     * are Voronoi regions rather than noise because "players need a mentally
     * navigable map", and half an outer ring of independently-rolled dead hexes
     * would be exactly the speckle that rules out.
     *
     * The column axis is stretched by ASPECT for the same reason the lakes and
     * the biome regions stretch theirs: hexes are wider than they are tall
     * (§13.2), so an unstretched field would draw regions out along the columns
     * and they would read as stripes rather than as country.
     */
    public static function barrenField(int $col, int $row): float
    {
        $cell = Balance::BARREN_CELL;
        $x = $col / ($cell / self::ASPECT);
        $y = $row / $cell;

        $x0 = (int) floor($x);
        $y0 = (int) floor($y);
        $fx = self::smoothstep($x - $x0);
        $fy = self::smoothstep($y - $y0);

        $seed = Balance::mapSeed() ^ 0x2B1E;
        $c00 = Hash::rand01(Hash::hash2($x0, $y0, $seed));
        $c10 = Hash::rand01(Hash::hash2($x0 + 1, $y0, $seed));
        $c01 = Hash::rand01(Hash::hash2($x0, $y0 + 1, $seed));
        $c11 = Hash::rand01(Hash::hash2($x0 + 1, $y0 + 1, $seed));

        return ($c00 * (1 - $fx) + $c10 * $fx) * (1 - $fy)
            + ($c01 * (1 - $fx) + $c11 * $fx) * $fy;
    }

    private static function smoothstep(float $t): float
    {
        return $t * $t * (3 - 2 * $t);
    }

    /**
     * §5.2 -- is this hex dead ground?
     *
     * Dead is not depleted. A depleted hex is drained and regrows in about nine
     * hours (§5.1); this one never had a seam and never will, so it keeps no
     * timer, shows no remnants and is drawn in grey rather than in a tired
     * version of its own biome (§13.3).
     */
    public static function isBarren(int $col, int $row, string $ring): bool
    {
        return self::barrenField($col, $row) < (Balance::BARREN_THRESHOLD[$ring] ?? 0.0);
    }

    public static function waterAt(int $col, int $row): ?string
    {
        if (self::lakeAt($col, $row)) {
            return 'lake';
        }

        return self::riverAt($col, $row) ? 'river' : null;
    }

    /**
     * Four waterways: two running east-west, two north-south.
     *
     * Their lines are fractions of the radius rather than hex counts, so the
     * same four rivers cross a test map and a ship-scale one. None of the four
     * passes through the middle -- the barren ring keeps the dungeon mouths,
     * and a river mouth there would read as a way in.
     *
     * @var array<int,array{0:int,1:float}>
     */
    private const RIVER_LINES = [
        [0, -0.55],
        [0, 0.46],
        [1, -0.44],
        [1, 0.57],
    ];

    private static function riverAmplitude(): int
    {
        return max(4, (int) round(Balance::mapRadius() * Balance::RIVER_AMPLITUDE));
    }

    /**
     * Where a waterway's channel sits at one step along its length.
     *
     * Value noise: one hashed offset every RIVER_SEGMENT hexes, smoothstepped
     * between. Polynomial interpolation keeps the curve identical on both
     * sides of the wire, which a trig-driven meander could not promise.
     */
    private static function riverCenter(int $index, int $t): float
    {
        $segment = Balance::RIVER_SEGMENT;
        $cell = (int) floor($t / $segment);
        $f = ($t - $cell * $segment) / $segment;

        $amplitude = self::riverAmplitude();
        $a = Hash::randInt(
            Hash::hash2($cell, $index, Balance::mapSeed() ^ 0x21CE),
            -$amplitude,
            $amplitude,
        );
        $b = Hash::randInt(
            Hash::hash2($cell + 1, $index, Balance::mapSeed() ^ 0x21CE),
            -$amplitude,
            $amplitude,
        );

        $u = $f * $f * (3.0 - 2.0 * $f);
        $base = round(self::RIVER_LINES[$index][1] * Balance::mapRadius());

        return $base + $a + ($b - $a) * $u;
    }

    /**
     * A hex is in the channel if it lies between this step's center and the
     * next one's, which is what keeps a steep reach unbroken: consecutive
     * bands share an endpoint, so the water is continuous by construction
     * rather than by picking a width that happens to cover the slope.
     */
    private static function riverAt(int $col, int $row): bool
    {
        $half = Balance::RIVER_HALF_WIDTH;

        foreach (self::RIVER_LINES as $index => [$axis, $_]) {
            $along = $axis === 0 ? $col : $row;
            $across = $axis === 0 ? $row : $col;

            $here = self::riverCenter($index, $along);
            $next = self::riverCenter($index, $along + 1);

            if ($across >= min($here, $next) - $half && $across <= max($here, $next) + $half) {
                return true;
            }
        }

        return false;
    }

    /**
     * One candidate lake per cell, found by scanning the 3x3 around this hex.
     *
     * The same lattice trick the settlements use, and for the same reason: a
     * blob can be tested for without enumerating it, so a tile costs a fixed
     * handful of hashes however many lakes the map holds.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function lakeIn(int $cellCol, int $cellRow): ?array
    {
        $cell = Balance::LAKE_CELL;

        if (Hash::rand01(Hash::hash2($cellCol, $cellRow, Balance::mapSeed() ^ 0x1A4E)) > Balance::LAKE_CHANCE) {
            return null;
        }

        // Inset from the cell edge so a lake never reaches past the 3x3 the
        // scan above covers.
        $inset = Balance::LAKE_MAX_RADIUS + 2;

        return [
            $cellCol * $cell + Hash::randInt(
                Hash::hash2($cellCol, $cellRow, Balance::mapSeed() ^ 0x1A5E),
                $inset,
                $cell - $inset - 1,
            ),
            $cellRow * $cell + Hash::randInt(
                Hash::hash2($cellRow, $cellCol, Balance::mapSeed() ^ 0x1A6E),
                $inset,
                $cell - $inset - 1,
            ),
            Hash::randInt(
                Hash::hash2($cellCol + $cellRow, $cellCol - $cellRow, Balance::mapSeed() ^ 0x1A7E),
                Balance::LAKE_MIN_RADIUS,
                Balance::LAKE_MAX_RADIUS,
            ),
        ];
    }

    private static function lakeAt(int $col, int $row): bool
    {
        $cell = Balance::LAKE_CELL;
        $cx = (int) floor($col / $cell);
        $cy = (int) floor($row / $cell);

        // §13.2 -- hexes are wider than they are tall, so the same weighting
        // the biome regions use keeps a lake round on screen instead of drawn
        // out along the columns.
        $wobble = Hash::rand01(Hash::hash2($col, $row, Balance::mapSeed() ^ 0x1A8E))
            * Balance::LAKE_EDGE_WOBBLE - Balance::LAKE_EDGE_WOBBLE / 2;

        for ($i = -1; $i <= 1; $i++) {
            for ($j = -1; $j <= 1; $j++) {
                $site = self::lakeIn($cx + $i, $cy + $j);
                if ($site === null) {
                    continue;
                }

                $dx = ($site[0] - $col) * self::ASPECT;
                $dy = $site[1] - $row;

                if (sqrt($dx * $dx + $dy * $dy) + $wobble < $site[2]) {
                    return true;
                }
            }
        }

        return false;
    }

    // ---------------------------------------------------------- settlements

    /**
     * Settlements sit on a jittered lattice: one candidate site per cell, so a
     * region can be enumerated without storing anything. Cell size per tier is
     * what produces "villages > cities > capitals" in count -- §6 calls that a
     * cost curve outcome, and this is the generation half of it.
     *
     * `minGap` is the guaranteed floor on the distance between two settlements
     * of the same tier, in hexes. A cell alone does not give one: a site free to
     * land anywhere in its cell can sit against the shared edge of two cells,
     * which put villages on touching hexes. self::siteOffset narrows the window.
     */
    private const LATTICE = [
        'village' => ['cell' => 11, 'minGap' => 8, 'chance' => 0.8, 'salt' => 0x1111],
        'city' => ['cell' => 14, 'minGap' => 11, 'chance' => 0.45, 'salt' => 0x2222],
        'capital' => ['cell' => 26, 'minGap' => 15, 'chance' => 0.7, 'salt' => 0x3333],
    ];

    /**
     * Where inside its cell a site sits, on one axis.
     *
     * The window a site may choose from is narrower than the cell and centerd
     * in it, leaving a margin at each edge. Two sites in neighboring cells are
     * then at least `cell - window + 1` apart on that axis -- which is `minGap`
     * -- and hex distance is never less than the larger axial difference, so the
     * floor holds diagonally too.
     *
     * Mirrored in worldgen.ts siteOffset(). The parity fixture pins both.
     */
    private static function siteOffset(int $cell, int $minGap, int $h): int
    {
        $window = $cell - $minGap + 1;
        $margin = intdiv($cell - $window, 2);

        return $margin + Hash::randInt($h, 0, $window - 1);
    }

    /**
     * §5.2 -- which tier, if any, each concentric ring carries.
     *
     * Capitals sit in the contested ring, not the dead center: the walk to a
     * capital bench is meant to cross ground other prospectors are working, and
     * the center is reserved for dungeon mouths alone. Both of those rings are
     * PvP ground.
     */
    private const TIER_FOR_RING = [
        'outer' => 'village',
        'mid' => 'city',
        'inner' => 'capital', // contested, and where the best bench stands
        'center' => null,     // dungeon mouths only: no settlement of any tier stands here
    ];

    /** Weakest first. A tier yields to everything above it and to nothing below. */
    private const TIER_ORDER = ['village', 'city', 'capital'];

    /** Where a tier's candidate sits inside one cell. Position only -- this says
     *  nothing about whether the cell actually fills. */
    private static function siteIn(string $tier, int $cellCol, int $cellRow): array
    {
        ['cell' => $cell, 'minGap' => $minGap, 'salt' => $salt] = self::LATTICE[$tier];

        return [
            $cellCol * $cell + self::siteOffset($cell, $minGap, Hash::hash2($cellCol, $cellRow, Balance::mapSeed() ^ $salt)),
            $cellRow * $cell + self::siteOffset($cell, $minGap, Hash::hash2($cellRow, $cellCol, Balance::mapSeed() ^ ($salt + 1))),
        ];
    }

    /** Not every cell gets a settlement -- that is what makes density organic. */
    private static function cellFills(string $tier, int $cellCol, int $cellRow): bool
    {
        ['chance' => $chance, 'salt' => $salt] = self::LATTICE[$tier];

        return Hash::rand01(Hash::hash2($cellCol, $cellRow, Balance::mapSeed() ^ ($salt + 2))) <= $chance;
    }

    /**
     * The settlement of this tier standing in this cell, or null.
     *
     * Everything a site has to pass except the crowding test, which is
     * deliberately left out: that is what self::crowdedByBetter asks about its
     * *neighbors*, and putting it here would recurse.
     */
    private static function settledSite(string $tier, int $cellCol, int $cellRow): ?array
    {
        if (! self::cellFills($tier, $cellCol, $cellRow)) {
            return null;
        }

        [$col, $row] = self::siteIn($tier, $cellCol, $cellRow);

        // A site generated for one ring but landing in another is not a
        // settlement of that tier -- tiers never bleed across ring boundaries.
        if (self::TIER_FOR_RING[self::ringOf($col, $row)] !== $tier) {
            return null;
        }

        return [$col, $row];
    }

    /**
     * §6.0 -- where two tiers could crowd, the *higher* tier's gap applies and
     * the lower tier is the one that yields. A village keeps a city's 11 hexes
     * rather than its own 8; a city is never moved by a village.
     *
     * Same-tier spacing is guaranteed by construction (see self::siteOffset).
     * This one cannot be, because the two tiers sit on lattices of different
     * sizes and no choice of window separates them. So it is a rejection
     * instead, costing one small lattice scan per higher tier -- and only for a
     * candidate that has already earned its place, which is a few dozen tiles
     * in every ten thousand.
     *
     * Not recursive, and it does not need to be: a capital can only ever
     * suppress a city, and the whole barren inner ring lies between those two
     * tiers, so that pair never comes within reach. Revisit if §5.2 moves a
     * ring boundary.
     *
     * Mirrored in worldgen.ts crowdedByBetter(). The parity fixture pins both.
     */
    private static function crowdedByBetter(string $tier, int $col, int $row): bool
    {
        $rank = array_search($tier, self::TIER_ORDER, true);

        foreach (array_slice(self::TIER_ORDER, $rank + 1) as $above) {
            ['cell' => $cell, 'minGap' => $minGap] = self::LATTICE[$above];

            // Hex distance is never below the larger axial difference, so
            // anything within minGap hexes is also within minGap columns and
            // rows -- these are every cell that could hold one. Cell indices go
            // negative west and north of the origin (§5.1) and those cells are
            // as real as any other, so the scan must not clamp them away.
            $cxMin = (int) floor(($col - $minGap) / $cell);
            $cyMin = (int) floor(($row - $minGap) / $cell);
            $cxMax = (int) floor(($col + $minGap) / $cell);
            $cyMax = (int) floor(($row + $minGap) / $cell);

            for ($cx = $cxMin; $cx <= $cxMax; $cx++) {
                for ($cy = $cyMin; $cy <= $cyMax; $cy++) {
                    $site = self::settledSite($above, $cx, $cy);
                    if ($site !== null && HexGeometry::distance($col, $row, $site[0], $site[1]) < $minGap) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

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
            $h = Hash::hash2($col, $row + $i * 977, Balance::mapSeed() ^ 0x5171);
            $index = Hash::randInt($h, 0, count($pool) - 1);
            $picked[] = $pool[$index];
            array_splice($pool, $index, 1);
        }

        return $picked;
    }

    private static function nameFor(int $col, int $row, string $tier): string
    {
        $hp = Hash::hash2($col, $row, Balance::mapSeed() ^ 0x7AE1);
        $hs = Hash::hash2($row, $col, Balance::mapSeed() ^ 0x7AE2);

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
        // §5.1 -- nobody lives off the edge. The lattice happily extends past
        // it, so without this the slow path and the atlas's cell walk disagree
        // about the border cells.
        if (! self::inBounds($col, $row)) {
            return null;
        }

        $ring = self::ringOf($col, $row);
        $tier = self::TIER_FOR_RING[$ring];
        if ($tier === null) {
            return null;
        }

        $cell = self::LATTICE[$tier]['cell'];
        $cellCol = (int) floor($col / $cell);
        $cellRow = (int) floor($row / $cell);

        // Cheapest rejection first, and it turns away almost every tile: this
        // one is not the site its cell chose.
        [$siteCol, $siteRow] = self::siteIn($tier, $cellCol, $cellRow);
        if ($siteCol !== $col || $siteRow !== $row) {
            return null;
        }

        if (! self::cellFills($tier, $cellCol, $cellRow)) {
            return null;
        }

        // The ring test self::settledSite makes is already satisfied here:
        // $tier was read from this tile's own ring, and the site is this tile.
        if (self::crowdedByBetter($tier, $col, $row)) {
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
        $radius = Balance::RING_CENTER * 0.62 * Balance::mapRadius();

        foreach (Catalog::DUNGEONS as $index => $dungeon) {
            $angle = (M_PI * 2 * $index) / $count - M_PI / 2;
            $sites[] = [
                'col' => (int) round(cos($angle) * $radius),
                'row' => (int) round(sin($angle) * $radius),
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
     * §5.7 -- is this ground briefly worth more than usual, and until when.
     *
     * The same trick a pack runs on (§9.5.1): a time bucket hashed with the
     * hex, so a pocket nobody has walked onto costs no storage and every client
     * agrees on where it is.
     *
     * No biome test. A pocket is not a line: it is the hex being good today,
     * and it pays into whatever that hex already trains -- so keeping it to one
     * biome would be handing one of the five a bonus the other four never see,
     * which is the thing §8 rule 4 says not to do.
     */
    private static function pocketUntil(int $col, int $row, int $now): ?int
    {
        $lifetime = Balance::scaled(Balance::POCKET_LIFETIME_MS);
        $bucket = intdiv($now, $lifetime);

        // A different salt and a different mix from the pack's, or the two
        // would land on the same hexes on the same schedule for ever.
        $h = Hash::hash2($col * 13 + $bucket, $row * 41 + $bucket, Balance::mapSeed() ^ 0x0DDF);

        if (Hash::rand01($h) > Balance::POCKET_CHANCE) {
            return null;
        }

        return ($bucket + 1) * $lifetime;
    }

    /**
     * §9.5.1 -- is a pack standing here, and which one.
     *
     * A time bucket hashed with the hex, so the whole thing is derivable and a
     * pack nobody has met costs no storage.
     *
     * The OFFSET is what a plain bucket lacks. One that started at the same
     * instant on every hex would blink the whole world at once; with two
     * hours between rolls and a pin on the far end of one (§9.5.3), a synchronised
     * world would empty and refill on a heartbeat everybody could set a watch by.
     * A per-hex offset staggers them: every hex keeps its own two-hour rhythm and
     * the map churns continuously.
     *
     * `until` is returned in the caller's time base rather than the bucket's, so
     * nothing outside here has to know the offset exists.
     */
    private static function packAt(int $col, int $row, string $ring, int $now): ?array
    {
        if (! Balance::packsEnabled()) {
            return null;
        }

        $lifetime = Balance::scaled(Balance::PACK_LIFETIME_MS);
        $offset = Hash::randInt(
            Hash::hash2($col, $row, Balance::mapSeed() ^ 0x9AC1),
            0,
            max(0, $lifetime - 1),
        );

        $bucket = intdiv($now + $offset, $lifetime);
        $h = Hash::hash2($col * 37 + $bucket, $row * 19 + $bucket, Balance::mapSeed() ^ 0x5EED);

        if (Hash::rand01($h) > (Balance::PACK_CHANCE[$ring] ?? 0.0)) {
            return null;
        }

        // §9.5.2 -- a ring fights its own two and the two from outside it, so
        // which of the four turns up is another roll on the same bucket.
        $pool = Monsters::BY_RING[$ring] ?? [];
        if ($pool === []) {
            return null;
        }

        $pick = Hash::randInt(
            Hash::hash2($col * 41 + $bucket, $row * 23 + $bucket, Balance::mapSeed() ^ 0x77A3),
            0,
            count($pool) - 1,
        );

        return [
            'key' => $pool[$pick],
            'bucket' => $bucket,
            'until' => ($bucket + 1) * $lifetime - $offset,
        ];
    }

    /**
     * §5.3 -- which of the biome's four variants this hex turned out to be.
     *
     * A weighted walk in fixed grade order over the ring's column, which sums
     * to 1 by construction (the generator asserts it). Fixed order is what
     * makes the roll reproducible on the client: both sides walk the same table
     * with the same number and stop in the same place.
     *
     * The outer rim can only ever be the base grade, so a walk there always
     * stops on the first row -- safe ground and poor ground, exactly as §5.2
     * asks for.
     *
     * @return array{key:string,grade:string,name:string,material:string,tint:string,props:string,weights:array<string,float>}
     */
    public static function variantOf(int $col, int $row, string $biome, string $ring): array
    {
        $variants = Variants::BIOME_VARIANTS[$biome];
        $roll = Hash::rand01(Hash::hash2($col, $row, Balance::mapSeed() ^ 0xC3));

        // §5.2 -- the center rolls on the inner ring's table, because it IS the
        // contested ring: the same grades, the same Tier 3 rate. The weight
        // table has three columns and gains no fourth, so the alias lives here
        // rather than as a duplicated column that could drift from its twin.
        $column = $ring === 'center' ? 'inner' : $ring;

        $seen = 0.0;
        foreach ($variants as $variant) {
            $seen += $variant['weights'][$column] ?? 0.0;
            if ($roll < $seen) {
                return $variant;
            }
        }

        return $variants[0];
    }

    /**
     * §5.3 -- a hex's HP, scaled by the grade of ground it turned out to be.
     *
     * The roll is the same 2,700-5,400 it always was; what the grade decides is
     * the rung that roll is measured at. Base ground is the common rung, so it
     * comes through untouched, and an Ironwood Grove is four and two thirds
     * times the work because that is the ratio between an Ironwood Axe and a
     * Stone one -- the better ground asks for the better tool by costing what
     * the better tool is worth.
     *
     * Integer arithmetic on purpose. A float multiplier would be two
     * generators rounding a repeating decimal and hoping (scripts/parity.ts).
     */
    public static function tileHp(int $hash, string $grade): int
    {
        $roll = Hash::randInt($hash, Balance::TILE_HP_MIN, Balance::TILE_HP_MAX);
        $attack = Balance::TILE_HP_GRADE_ATTACK[$grade] ?? Balance::MINING_COMMON_ATTACK;

        return intdiv($roll * $attack, Balance::MINING_COMMON_ATTACK);
    }

    /**
     * §5.1 -- how many hauls this hex has in it, from what one haul is worth.
     *
     * Inverse and linear across the band: the richest ground gives up
     * TILE_EXTRACTIONS_MIN hauls and the poorest TILE_EXTRACTIONS_MAX. What a
     * hex is worth over its whole life therefore comes out roughly level, and
     * what a better hex actually buys is FEWER WALKS for the same total -- which
     * is the thing a map this size can charge for.
     *
     * Integer arithmetic, so the two generators cannot round apart
     * (scripts/parity.ts).
     */
    public static function tileExtractions(int $baseYield): int
    {
        $span = Balance::TILE_YIELD_MAX - Balance::TILE_YIELD_MIN;
        $drop = Balance::TILE_EXTRACTIONS_MAX - Balance::TILE_EXTRACTIONS_MIN;
        $over = max(0, min($span, $baseYield - Balance::TILE_YIELD_MIN));

        return Balance::TILE_EXTRACTIONS_MAX - intdiv($over * $drop, $span);
    }

    /**
     * Build a tile. $mutation carries the only server-owned state a tile has;
     * everything else is derived.
     *
     * @param  array{slotsUsed?:int,regrowsAt?:int,packCleared?:bool}  $mutation
     */
    public static function generateTile(int $col, int $row, int $now, array $mutation = []): array
    {
        $ring = self::ringOf($col, $row);
        $biome = self::biomeOf($col, $row);
        $settlement = self::settlementAt($col, $row);
        $dungeon = self::dungeonAt($col, $row);

        $hTime = Hash::hash2($col, $row, Balance::mapSeed() ^ 0xA1);
        $hYield = Hash::hash2($col, $row, Balance::mapSeed() ^ 0xB2);
        $baseYield = Hash::randInt($hYield, Balance::TILE_YIELD_MIN, Balance::TILE_YIELD_MAX);

        // §5.2 -- what pressure there is toward the middle is the seam gradient
        // now (MINEABLE_SHARE), not a hole in the map. The center used to be
        // excluded here outright.
        //
        // A depleted tile keeps its material: it is drained, not dead (§5.1), and
        // callers gate on regrowsAt. The UI needs the material to keep rendering
        // the right remnants while it regrows.
        // §5.3 -- water yields to the things that are placed rather than
        // grown. A settlement on a river is a ford and a dungeon mouth is a
        // fixed site; moving either to make room would mean the lattice had to
        // know about the water, and the water is the cheaper thing to bend.
        $water = $settlement === null && $dungeon === null
            ? self::waterAt($col, $row)
            : null;

        // §5.2 -- dead ground, and the one thing that decides whether a hex has
        // a seam in it at all. The center used to be excluded here outright --
        // "barren of everything" -- and is now ordinary contested ground that
        // takes its chances with the same field as the three rings around it.
        $barren = $settlement === null && $water === null && $dungeon === null
            && self::isBarren($col, $row, $ring);

        $variant = null;
        $material = null;
        if (! $barren && $settlement === null && $water === null) {
            $variant = self::variantOf($col, $row, $biome, $ring);
            $material = $variant['material'];
        }

        $regrowsAt = $mutation['regrowsAt'] ?? 0;

        return [
            'col' => $col,
            'row' => $row,
            'biome' => $biome,
            'variant' => $variant['key'] ?? $biome,
            'ring' => $ring,
            'material' => $material,
            // §5.2 -- no seam, and never will have one. It is NOT a variant of
            // its own: dead ground wears the colour of the country it sits in,
            // so a waste is invisible at a distance and obvious underfoot. What
            // tells you is the props, and §13.2 draws those in sight only.
            'dead' => $barren,
            'hp' => self::tileHp($hTime, $variant['grade'] ?? 'common'),
            'baseYield' => $baseYield,
            'extractions' => self::tileExtractions($baseYield),
            'slotsUsed' => $mutation['slotsUsed'] ?? 0,
            'regrowsAt' => $regrowsAt,
            'settlement' => $settlement,
            'dungeon' => $dungeon ? ['key' => $dungeon['key'], 'name' => $dungeon['name']] : null,
            'water' => $water,
            // §5.7 -- and the ground itself may be having a good few hours.
            // The test is the SEAM itself: a pocket is a hex worth more to
            // work, so a hex with nothing to work on cannot have one. Dead ground and a lake fall
            // out of that for free, and so does a settlement, which §6 says is
            // worked ground with nothing left to take.
            'pocketUntil' => $material !== null && $regrowsAt <= $now
                ? self::pocketUntil($col, $row, $now)
                : null,
            // §9.5.1 -- nothing camps on open water, and nothing camps on a
            // settlement or a dungeon mouth either: a pack parked on a capital
            // would lock a whole region out of the only five-line bench it has,
            // and blocking shared infrastructure is not a hazard, it is grief.
            // §9.5.1 -- `packCleared` is the one bit the seed cannot know: it
            // comes from the cache (Packs), and folding it in here means every
            // reader downstream sees the same absence rather than each one
            // remembering to ask.
            'pack' => $water === null && $settlement === null && $dungeon === null
                && empty($mutation['packCleared'])
                ? self::packAt($col, $row, $ring, $now)
                : null,
            'propSeed' => Hash::hash2($col, $row, Balance::mapSeed() ^ 0xF00D),
        ];
    }
}
