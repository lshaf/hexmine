<?php

declare(strict_types=1);

/**
 * Emit app/Game/Skills.php -- §7.4's tree, collapsed into levelled skills.
 *
 *     php scripts/gen_skills.php
 *
 * A skill is one entry with RANKS, and a rank is one of Jobs::NODES. That
 * indirection is the whole design: the node table stays the effect ledger, so
 * every value, every level gate and every cap in Balance is untouched and the
 * balance of the game does not move by a single point. What changes is how many
 * THINGS a player is asked to read -- 495 nodes become 95 skills, because the
 * 495 were never 495 ideas. Explorer's fifteen are two.
 *
 * Rank order is the prerequisite. `requires` on a node is no longer read: rank 3
 * needs rank 2 by being rank 3, which is what a level ladder means. That does
 * cost §7.4.2's forks -- a capstone naming two parents is gone -- and it is the
 * trade the shape makes.
 *
 * Written in PHP rather than Python because the input is Jobs::NODES itself:
 * the generator that produced those is stale (it drops every battleSkill), so
 * anything reading the spec instead of the file would rebuild the wrong tree.
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Game\Jobs;

/*
 * One name per (job, kind), in the units the effect is actually felt in.
 *
 * Keyed by kind, with a per-job override where one word will not do for all of
 * them -- a Sawyer's clock and a Smith's clock are the same StatKey and are not
 * the same sentence.
 */
$NAMES = [
    'bagSlots' => ['Straps', 'Places on the bag to put something.'],
    'sight' => ['Horizon', 'How many hexes of live ground you can read.'],
    'bite' => ['Bite', 'Attack against the hex, on this line only.'],
    'toolWear' => ['Tool Care', 'Mines that leave the line\'s tool untouched.'],
    'seamGrade' => ['Seam Eye', 'Mines that come up a grade past what the tool reliably takes.'],
    'costReduction' => ['Thrift', 'Fewer inputs per run.'],
    'presence' => ['Attendance', 'Worth more for standing at the bench while it works.'],
    'batch' => ['Yield Batch', 'More output per run.'],
    'runSlot' => ['Second Pit', 'Runs of this line you may keep going at once.'],
    'craftDurability' => ['Temper', 'Higher starting durability on what you make.'],
    'craftOption' => ['Maker\'s Luck', 'Chance of an extra rolled line on what you make.'],
    'optionTier' => ['Deep Draw', 'Chance a rolled line is drawn a grade deeper.'],
    'stackCap' => ['Cellar', 'A deeper shelf for each draft carried.'],
    'brewExtra' => ['Extra Flask', 'Chance of an extra flask off a brew.'],
    'pair' => ['Guard and Edge', 'Solid points of attack and defense.'],
    'battleWear' => ['Kit Care', 'A share of what a fight takes off the worn kit, spared.'],
    'weaponWear' => ['Blade Care', 'The same for the blade, which pays its own stream.'],
    'skillPower' => ['Skill Power', 'More of the extra on your family\'s three skills.'],
    'skillCooldown' => ['Skill Tempo', 'Whole rounds off every one of their cooldowns.'],
    'skillStun' => ['Heavy Hand', 'A round longer on a stun.'],
    'goldFind' => ['Purse', 'More of what a pack pays.'],
    'lootOption' => ['Scavenger', 'Chance of an extra rolled line on looted gear.'],
];

/** Where one word will not do for every job that carries the kind. */
$OVERRIDES = [
    'stat' => [
        'woodcutting' => ['Woodsman\'s Eye', 'A bigger haul off a forest hex.'],
        'mining' => ['Ore Sense', 'A bigger haul off a mountain seam.'],
        'hunting' => ['Field Dressing', 'More off the animal you take.'],
        'quarrying' => ['Stonecutting', 'A bigger haul off badlands stone.'],
        'harvesting' => ['Reaping', 'A bigger haul off a grassland hex.'],
        'sawyer' => ['Saw Speed', 'A faster run at a saw pit.'],
        'smelter' => ['Furnace Draft', 'A faster run at a furnace.'],
        'tanner' => ['Pit Speed', 'A faster run at a tannery.'],
        'mason' => ['Chisel Speed', 'A faster run at a masonry.'],
        'weaver' => ['Loom Speed', 'A faster run at a loom.'],
        'smith' => ['Forge Speed', 'A faster hour at the weapon bench.'],
        'armorer' => ['Fitting Speed', 'A faster hour at the armor bench.'],
        'alchemist' => ['Brewing Speed', 'A faster hour at the consumable bench.'],
    ],
    'batch' => ['alchemist' => ['Rack', 'More flasks off a brew.']],
];

$skills = [];

foreach (Jobs::NODES as $key => $node) {
    $kind = $node['effect']['kind'];

    // §9.5.9 -- a battle skill is its own one-rank entry: owning it IS the
    // effect, so three of them are three skills and never three ranks of one.
    $skillKey = $kind === 'battleSkill' ? $key : "{$node['job']}.{$kind}";

    if ($kind === 'battleSkill') {
        $name = $node['name'];
        $blurb = $node['description'];
    } else {
        [$name, $blurb] = $OVERRIDES[$kind][$node['job']] ?? $NAMES[$kind]
            ?? throw new RuntimeException("no name for {$kind}");
    }

    $skills[$skillKey] ??= [
        'job' => $node['job'],
        'kind' => $kind,
        'name' => $name,
        'description' => $blurb,
        'ranks' => [],
    ];

    $skills[$skillKey]['ranks'][] = ['node' => $key, 'level' => $node['jobLevel']];
}

// A ladder climbs, so the ranks are ordered by the level that opens them. Ties
// keep the node order the tree already had.
foreach ($skills as &$skill) {
    usort($skill['ranks'], static fn (array $a, array $b) => $a['level'] <=> $b['level']);
}
unset($skill);

// Order the skills by their first rank's level, so a list reads in the order it
// becomes available -- which is the order a player meets it in.
uasort($skills, static function (array $a, array $b): int {
    return $a['ranks'][0]['level'] <=> $b['ranks'][0]['level']
        ?: strcmp($a['name'], $b['name']);
});

$php = static fn (string $s): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'";

$out = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Game;\n\n";
$out .= <<<'DOC'
/**
 * §7.4 -- the skills a job teaches, and the ranks each one climbs.
 *
 * Generated by `php scripts/gen_skills.php` -- do not edit by hand.
 *
 * A job used to be thirty separate nodes drawn as a diagram, and the thirty
 * were never thirty ideas: they were a handful of effects repeated under
 * different names. Explorer's fifteen were two -- straps thirteen times and
 * sight twice -- and Deep Pockets, Second Strap, Rolled Blanket, Even Load,
 * Side Pouch, Bindle, Sorted Kit, Tump Line, Packer's Knot, Outer Pockets and
 * Long Haul were eleven different words for the same +2.
 *
 * So a skill is ONE entry with ranks, and 495 nodes are 95 skills.
 *
 * **A rank IS a node.** Every rank names one of Jobs::NODES, which stays the
 * effect ledger -- so every value, every level gate and every cap in Balance is
 * exactly what it was and the balance of the game has not moved by a point.
 * What changed is how many things a player is asked to read.
 *
 * **Rank order is the prerequisite.** Rank 3 needs rank 2 by being rank 3,
 * which is what a ladder means, so `requires` is no longer read anywhere. That
 * costs the forks §7.4.2 argued for -- a capstone naming two parents is gone --
 * and it is the trade the shape makes: what a tree bought with its branching
 * was a shape to plan, and what it cost was thirty rows nobody could hold in
 * their head.
 *
 * **A battle skill is a one-rank skill** (§9.5.9): owning it is the whole
 * effect, so Onslaught, Sunder and Riposte are three entries rather than three
 * ranks of one, and they sit in the same list as the levelled ones.
 */
DOC;
$out .= "\nfinal class Skills\n{\n";
$out .= "    /**\n     * Keyed by skill. `ranks` are ordered by the level that opens them, and\n"
    . "     * each names the Jobs::NODES row that carries its effect.\n     *\n"
    . "     * @var array<string,array<string,mixed>>\n     */\n";
$out .= "    public const ALL = [\n";

foreach ($skills as $key => $skill) {
    $out .= "        {$php($key)} => [\n";
    $out .= "            'job' => {$php($skill['job'])},\n";
    $out .= "            'kind' => {$php($skill['kind'])},\n";
    $out .= "            'name' => {$php($skill['name'])},\n";
    $out .= "            'description' => {$php($skill['description'])},\n";
    $out .= "            'ranks' => [\n";
    foreach ($skill['ranks'] as $rank) {
        $out .= "                ['node' => {$php($rank['node'])}, 'level' => {$rank['level']}],\n";
    }
    $out .= "            ],\n";
    $out .= "        ],\n";
}

$out .= "    ];\n\n";

// The helpers are code rather than data, and they are written here so the file
// stays one thing: a generated table nobody edits, and the four questions
// everybody asks of it.
$out .= <<<'CODE'
    /** Every skill a job teaches, in the order it becomes available. */
    public static function forJob(string $job): array
    {
        return array_filter(self::ALL, static fn (array $s) => $s['job'] === $job);
    }

    public static function of(string $key): ?array
    {
        return self::ALL[$key] ?? null;
    }

    /** How many ranks this skill climbs to. */
    public static function rankCount(string $key): int
    {
        return count(self::ALL[$key]['ranks'] ?? []);
    }

    /**
     * The job level that opens a given rank, 1-indexed, or null past the top.
     *
     * This is the whole gate: a rank is reachable when the job has reached the
     * level on it, exactly as a tier used to open a row.
     */
    public static function levelForRank(string $key, int $rank): ?int
    {
        return self::ALL[$key]['ranks'][$rank - 1]['level'] ?? null;
    }

    /**
     * The Jobs::NODES keys a holding of `rank` ranks amounts to.
     *
     * This is the join that keeps the balance still: effects are still summed
     * off nodes (GameService::effectsOf), so a skill at rank 5 is exactly the
     * first five nodes it was made of and cannot be worth anything else.
     *
     * @return list<string>
     */
    public static function nodesUpTo(string $key, int $rank): array
    {
        $ranks = array_slice(self::ALL[$key]['ranks'] ?? [], 0, max(0, $rank));

        return array_column($ranks, 'node');
    }

    /**
     * Which skill a node became, for anything still speaking in nodes.
     *
     * Built once per request rather than stored: it is a pure inversion of the
     * table above, and a second copy of it in the file would be a second thing
     * to regenerate.
     */
    public static function skillForNode(string $node): ?string
    {
        static $map = null;

        if ($map === null) {
            $map = [];
            foreach (self::ALL as $key => $skill) {
                foreach ($skill['ranks'] as $i => $rank) {
                    $map[$rank['node']] = [$key, $i + 1];
                }
            }
        }

        return $map[$node][0] ?? null;
    }

    /** Which rank of its skill a node is, 1-indexed. */
    public static function rankForNode(string $node): ?int
    {
        self::skillForNode($node);

        foreach (self::ALL as $key => $skill) {
            foreach ($skill['ranks'] as $i => $rank) {
                if ($rank['node'] === $node) {
                    return $i + 1;
                }
            }
        }

        return null;
    }
CODE;
$out .= "}\n";

file_put_contents(__DIR__ . '/../app/Game/Skills.php', $out);

$ranks = array_sum(array_map(static fn (array $s) => count($s['ranks']), $skills));
echo count($skills), " skills across ", $ranks, " ranks (from ", count(Jobs::NODES), " nodes)\n";
