<?php

declare(strict_types=1);

/**
 * §5.2 -- find the field cut that lands each ring on its mineable share.
 *
 *     php scripts/calibrate_barren.php [sample budget]
 *
 * Balance::MINEABLE_SHARE says how much of each ring should carry a seam;
 * Balance::BARREN_THRESHOLD is where WorldGen::barrenField() has to be cut to
 * get there. The two cannot be derived from each other on paper, because the
 * lakes, the towns and the five dungeon mouths take their own share of the same
 * ground and the field is smooth rather than uniform -- so this scans the whole
 * map, sorts every workable hex by its field value, and reads off the quantile.
 *
 * Re-run it and paste the numbers into Balance when a share moves, when the map
 * seed changes, or when BARREN_CELL changes. The test in WorldParityTest is
 * what actually holds the shares honest afterwards.
 *
 * It SAMPLES rather than scanning, and on the shipping map it has to: 10001 a
 * side is a hundred million hexes, which is hours of a script nobody would run.
 * A coprime stride sized to a budget lands a few hundred thousand hexes spread
 * evenly over the sheet, which pins a percentage far tighter than the tolerance
 * the test holds it to -- and the one ring that samples thinly, the center, is
 * the one the old whole-map scan over-fitted at small radii: at radius 200 it
 * was 793 tiles against a field whose lattice cell is five, so the quantile it
 * read was a handful of noise corners rather than the field.
 */

use App\Game\Balance;
use App\Game\WorldGen;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// The field itself lives in WorldGen and is NOT copied here: a calibrator with
// its own private copy of the thing it is calibrating would keep agreeing with
// itself long after the world had moved.
$r = Balance::mapRadius();
// Per-ring: collect the field value of every tile that is currently mineable,
// plus the ring's total tile count.
$vals = ['outer' => [], 'mid' => [], 'inner' => [], 'center' => []];
$total = ['outer' => 0, 'mid' => 0, 'inner' => 0, 'center' => 0];
$blocked = ['outer' => 0, 'mid' => 0, 'inner' => 0, 'center' => 0]; // water / settlement / dungeon

// A stride coprime with every lattice the world is built on, so the samples
// walk the whole sheet rather than settling into a sublattice of it. Sized from
// a budget: small maps fall through to a stride of 1 and are scanned exactly.
//
// Coprimality is the part that is not fussiness. A stride of 15 against a
// dead-ground field whose cell is 5 samples the same fractional position inside
// every cell, so it reads one slice of the field and calls it the field -- the
// first run of this on the shipping map did exactly that and moved two
// thresholds by a point and a half of pure aliasing.
$budget = max(1, (int) ($argv[1] ?? 400000));
$size = Balance::mapSize();
$lattices = [Balance::BARREN_CELL, Balance::BIOME_CELL, Balance::LAKE_CELL, $size];
$stride = max(1, (int) floor($size / max(1, (int) sqrt($budget))));
while ($stride > 1) {
    $clean = true;
    foreach ($lattices as $l) {
        if (gcd($stride, $l) !== 1) {
            $clean = false;
        }
    }
    if ($clean) {
        break;
    }
    $stride++;
}

function gcd(int $a, int $b): int
{
    while ($b !== 0) {
        [$a, $b] = [$b, $a % $b];
    }

    return $a;
}

for ($c = -$r; $c <= $r; $c += $stride) {
    for ($w = -$r; $w <= $r; $w += $stride) {
        $ring = WorldGen::ringOf($c, $w);
        $total[$ring]++;
        // In the new world the center is ordinary ground, so "blocked" means
        // only the things that are placed: water, a town, a dungeon mouth.
        if (WorldGen::waterAt($c, $w) !== null
            || WorldGen::settlementAt($c, $w) !== null
            || WorldGen::dungeonAt($c, $w) !== null) {
            $blocked[$ring]++;

            continue;
        }
        $vals[$ring][] = WorldGen::barrenField($c, $w);
    }
}

$targets = Balance::MINEABLE_SHARE;

/*
 * The quantile is read off the POOLED field, not off each ring's own slice.
 *
 * barrenField() is stationary noise -- the same lattice, the same distribution,
 * everywhere on the sheet -- so a ring's slice of it is an estimate of one
 * thing, and the pool is a better estimate of that same thing. What actually
 * differs per ring is how much ground the water and the towns have already
 * taken, and that enters as the correction below.
 *
 * It matters most where it is least obvious: the center ring is well under a
 * per cent of the map, so its own slice is a few thousand hexes against a field
 * whose cell is five. Fitting a quantile to that reads a handful of noise
 * corners and calls it the field, which is how the old whole-map scan produced
 * a center threshold that missed its share by seven points on a map big enough
 * to check.
 */
$pool = array_merge(...array_values($vals));
sort($pool);
$poolN = count($pool);

printf("radius = %d, cell = %d, stride = %d, pooled field samples = %d\n", $r, Balance::BARREN_CELL, $stride, $poolN);
printf("%-8s %8s %8s %9s %10s %9s\n", 'ring', 'tiles', 'blocked', 'workable', 'want share', 'threshold');
$out = [];
foreach ($targets as $ring => $target) {
    $workableShare = $total[$ring] > 0 ? count($vals[$ring]) / $total[$ring] : 1.0;

    // What share of the ground that is NOT already taken has to keep a seam.
    // Above one means the ring cannot reach its target however the field is
    // cut -- the water and the towns alone have overdrawn it.
    $want = $workableShare > 0 ? $target / $workableShare : 1.0;

    $wantBarren = (int) round((1 - min(1.0, $want)) * $poolN);
    if ($wantBarren <= 0) {
        $th = 0.0;
    } elseif ($wantBarren >= $poolN) {
        $th = 1.0;
    } else {
        $th = ($pool[$wantBarren - 1] + $pool[$wantBarren]) / 2;
    }
    $out[$ring] = $th;
    printf("%-8s %8d %8d %9d %9.3f%% %9.4f\n", $ring, $total[$ring], $blocked[$ring], count($vals[$ring]), $want * 100, $th);
}
echo "\nthresholds: ";
foreach ($out as $k => $v) {
    printf("'%s' => %.4f, ", $k, $v);
}
echo "\n";
