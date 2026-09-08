<?php

declare(strict_types=1);

/**
 * §7.3/§9.5.4 -- a one-off repair of `character_items` rows the catalog moved
 * out from under.
 *
 * Two things happened to the catalog and neither reached the rows already in
 * the database. `SOLID_SCALE` multiplied every solid number by a hundred, so a
 * piece made before it reads as a hundredth of itself -- a Mythril Pickaxe at
 * 125 against a ceiling of 20,000 is one mine from being destroyed. And the
 * focus family was renamed to daggers, so fifteen keys stopped existing, which
 * leaves a row holding a name the catalog has never heard of and a ceiling of
 * nothing.
 *
 * Run it with no arguments to see what it would do, and with `--apply` to do
 * it:
 *
 *     php scripts/repair_items.php
 *     php scripts/repair_items.php --apply
 *
 * **Idempotent by construction**, which is what makes it safe to run anywhere
 * and twice: a fixed row's ceiling is no longer a hundredth of the recipe's and
 * its fill is no longer under the old maximum, so neither test fires again.
 * There is no ledger of having run, and it does not need one.
 *
 * Not a migration, deliberately. A migration is the schema's history and this
 * is a repair of one deployment's data -- the shape of the table never changed.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Game\Balance;
use App\Game\Catalog;
use Illuminate\Support\Facades\DB;

/** acb6c35, "Every solid number moves to SOLID_SCALE (100)". */
const SCALE_LANDED = '2026-09-07 09:13:18';

/**
 * §9.5.4 -- the keys stranded by the focus-to-daggers rename (7b64a77).
 *
 * "It was renamed to a pair of daggers and nothing else moved: every value,
 * cap, cooldown, level gate and measured matchup is exactly what it was." So
 * each of these is the same object under a new name and the row is carried
 * across rather than left holding a dead key.
 *
 * Derived rather than guessed: every pair below shares its rarity and its
 * exact input list, and all fifteen matched with none left over -- which is the
 * rename's own claim, checked.
 */
const RENAMED = [
    'cracked_focus' => 'chipped_knives',
    'bound_focus' => 'bound_knives',
    'sealed_focus' => 'whetted_knives',
    'knotted_rod' => 'notched_dirks',
    'corded_rod' => 'corded_dirks',
    'wound_rod' => 'paired_dirks',
    'rune_rod' => 'fanged_dirks',
    'barbed_rune_rod' => 'barbed_dirks',
    'silkbound_rune_rod' => 'silkbound_dirks',
    'silkweave_sigil' => 'silkweave_fangs',
    'fanged_sigil' => 'hooked_fangs',
    'sunken_sigil' => 'sunken_fangs',
    'the_long_word' => 'the_quick_pair',
    'the_whole_word' => 'the_whole_pair',
    'the_spoken_word' => 'the_last_pair',
];

$apply = in_array('--apply', $argv, true);
$scale = Balance::SOLID_SCALE;
$fixed = 0;
$orphans = [];

foreach (DB::table('character_items')->orderBy('id')->get() as $row) {
    $key = RENAMED[$row->item_key] ?? $row->item_key;
    $renamed = $key !== $row->item_key;

    $def = Catalog::item($key);
    if ($def === null) {
        $orphans[] = $row;
        continue;
    }

    $catalogMax = (int) ($def['maxDurability'] ?? 0);
    if ($catalogMax <= 0) {
        continue;
    }

    // A stored ceiling a hundred times under the recipe's is an old ceiling.
    // Nothing legitimate lands there: §8.0.2's band is ±7% and the only other
    // things that touch a ceiling -- a Smith's node, a rolled durability line
    // -- raise it.
    $staleCeiling = $row->max_durability !== null
        && (int) $row->max_durability <= intdiv($catalogMax, 10);

    // With no ceiling of its own a row already reads the catalog's, so only the
    // FILL can be behind. That needs a date as well as a size, because "under
    // one per cent" is also what a genuinely ruined piece looks like -- and a
    // piece made after the scaling landed is ruined rather than stale. Being
    // wrong here would hand somebody their durability back, which is the safe
    // direction to be wrong in; the date makes it moot.
    $staleFill = $staleCeiling
        || ($row->max_durability === null
            && (int) $row->durability <= intdiv($catalogMax, $scale)
            && $row->created_at < SCALE_LANDED);

    // §8.0.1 -- a flat line is a solid number too, and moves with the rest of
    // the piece. A `gain` or a `seam` is a share of the work and does not.
    $options = json_decode((string) ($row->options ?: '[]'), true) ?: [];
    $staleOptions = false;
    if ($staleFill) {
        foreach ($options as $i => $option) {
            if (($option['kind'] ?? null) === 'flat' && isset($option['value'])) {
                $options[$i]['value'] = (int) $option['value'] * $scale;
                $staleOptions = true;
            }
        }
    }

    if (! $staleFill && ! $staleCeiling && ! $renamed) {
        continue;
    }

    $update = [];
    if ($renamed) {
        $update['item_key'] = $key;
    }
    if ($staleFill) {
        $update['durability'] = (int) $row->durability * $scale;
    }
    if ($staleCeiling) {
        $update['max_durability'] = (int) $row->max_durability * $scale;
    }
    if ($staleOptions) {
        $update['options'] = json_encode($options);
    }

    printf(
        "%-34s #%-4d %7d/%-7s -> %7s/%-7s%s\n",
        $renamed ? $row->item_key.' → '.$key : $row->item_key,
        $row->id,
        $row->durability,
        $row->max_durability ?? 'recipe',
        $update['durability'] ?? $row->durability,
        $update['max_durability'] ?? ($row->max_durability ?? 'recipe'),
        $staleOptions ? '   lines ×'.$scale : '',
    );

    if ($apply) {
        DB::table('character_items')->where('id', $row->id)->update($update);
    }
    $fixed++;
}

foreach ($orphans as $row) {
    printf(
        "%-34s #%-4d %7d/%-7s   no catalog entry and no known rename — left alone\n",
        $row->item_key,
        $row->id,
        $row->durability,
        $row->max_durability ?? 'recipe',
    );
}

printf(
    "\n%s %d row%s%s\n",
    $apply ? 'Repaired' : 'Would repair',
    $fixed,
    $fixed === 1 ? '' : 's',
    $orphans === [] ? '' : ', '.count($orphans).' left stranded',
);
