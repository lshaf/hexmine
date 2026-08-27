<?php

declare(strict_types=1);

/**
 * §5.2 -- find the field cut that lands each ring on its mineable share.
 *
 *     php scripts/calibrate_barren.php [cell]
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
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Game\Hash;
use App\Game\Balance;
use App\Game\WorldGen;

// The field itself lives in WorldGen and is NOT copied here: a calibrator with
// its own private copy of the thing it is calibrating would keep agreeing with
// itself long after the world had moved.
$r = Balance::mapRadius();
// Per-ring: collect the field value of every tile that is currently mineable,
// plus the ring's total tile count.
$vals = ['outer'=>[], 'mid'=>[], 'inner'=>[], 'center'=>[]];
$total = ['outer'=>0, 'mid'=>0, 'inner'=>0, 'center'=>0];
$blocked = ['outer'=>0, 'mid'=>0, 'inner'=>0, 'center'=>0]; // water / settlement / dungeon

for ($c = -$r; $c <= $r; $c++) {
    for ($w = -$r; $w <= $r; $w++) {
        $t = WorldGen::generateTile($c, $w, 0);
        $ring = $t['ring'];
        $total[$ring]++;
        // In the new world the center is ordinary ground, so "blocked" means
        // only the things that are placed: water, a town, a dungeon mouth.
        if ($t['water'] !== null || $t['settlement'] !== null || $t['dungeon'] !== null) {
            $blocked[$ring]++;
            continue;
        }
        $vals[$ring][] = WorldGen::barrenField($c, $w);
    }
}

$targets = Balance::MINEABLE_SHARE;
printf("cell = %d\n", Balance::BARREN_CELL);
printf("%-8s %8s %8s %9s %10s %9s\n", 'ring','tiles','blocked','workable','target mine','threshold');
$out = [];
foreach ($targets as $ring => $target) {
    sort($vals[$ring]);
    $n = count($vals[$ring]);
    $wantMineable = (int) round($target * $total[$ring]);   // tiles that must keep a seam
    $wantBarren = $n - $wantMineable;                        // of the workable ones
    if ($wantBarren <= 0) { $th = 0.0; }
    elseif ($wantBarren >= $n) { $th = 1.0; }
    else { $th = ($vals[$ring][$wantBarren - 1] + $vals[$ring][$wantBarren]) / 2; }
    $out[$ring] = $th;
    printf("%-8s %8d %8d %9d %10d %9.4f\n", $ring, $total[$ring], $blocked[$ring], $n, $wantMineable, $th);
}
echo "\nthresholds: ";
foreach ($out as $k=>$v) printf("'%s' => %.4f, ", $k, $v);
echo "\n";
