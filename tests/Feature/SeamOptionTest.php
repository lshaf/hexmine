<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Catalog;
use App\Game\Drops;
use App\Game\Formulas;
use App\Game\GameService;
use App\Game\Variants;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §8.0.1 / §5.3 -- the rolled line that names a material.
 *
 * Every other option is about the piece: how hard it hits, how long it lasts,
 * how much comes home. This one is about the GROUND -- it bends §5.3's grade
 * weights toward one seam -- which is why it is the only line whose `stat` is
 * a material key and why only a gathering tool may carry it.
 */
final class SeamOptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * §8.0.1 -- everything a line's ground gives up, bar the commonest.
     *
     * Not the three grades alone. Most of what a mine actually brings home is
     * the bench stock standing beside the seam -- the herbs, the components,
     * the critter -- and an axe that runs to toadstool is a thing a player has
     * a use for.
     */
    public function test_a_line_offers_everything_its_ground_gives_up(): void
    {
        foreach (Catalog::TOOL_SLOT_SKILL as $slot => $line) {
            $materials = Catalog::seamMaterialsForSlot($slot);

            $this->assertGreaterThanOrEqual(8, count($materials), "{$line} offers too little");

            foreach ($materials as $key) {
                $this->assertArrayHasKey($key, Catalog::materials(), "{$key} is not a material");
            }

            // The base grade is what a hex mostly gives anyway; a line
            // promising more of it would read as luck and would not be. That
            // is the one rule the widening did not touch.
            $base = Catalog::SKILLS[$line]['material'] ?? null;
            $this->assertNotContains($base, $materials, "{$line} offers its own base grade");
        }
    }

    /**
     * §8.0.1 -- and never junk or scrap, which is the other half of that rule.
     *
     * §4 gives both the same sentence: a gold apiece, no recipe takes them,
     * they reach no tier. A line promising more of one is a bonus to nothing --
     * not an unlucky roll, which this pool is meant to have, but a dud.
     */
    public function test_no_line_may_favour_rubbish(): void
    {
        $offered = Catalog::gatherSeamMaterials();
        foreach (Catalog::TOOL_SLOT_SKILL as $slot => $line) {
            $offered = array_merge($offered, Catalog::seamMaterialsForSlot($slot));
        }

        foreach (array_unique($offered) as $key) {
            $this->assertNotContains($key, Catalog::SEAM_NEVER, "{$key} is rubbish and is on offer");

            // Checked off the DATA as well as off the list, so the two have to
            // agree: §4 puts junk and scrap at tier 0 and nothing else there.
            // A Tier 3 prices at zero because the trader will not touch a
            // capped rare (§3.2), which is the opposite of worthless.
            $this->assertGreaterThan(
                0,
                Catalog::materials()[$key]['tier'] ?? 0,
                "{$key} is tier 0, which is what rubbish is",
            );
        }
    }

    /**
     * §4.0/§7.3 -- a tool or a glove, and nothing else.
     *
     * Gathering has no tool: it is worked with the hands in the tool's place,
     * so there is nothing on the belt for a line like this to sit on and the
     * glove is what carries it. Every other worn piece works no ground at all.
     */
    public function test_only_a_tool_or_a_glove_may_roll_one(): void
    {
        foreach (Catalog::items() as $key => $def) {
            $slot = (string) ($def['slot'] ?? '');
            $kinds = array_column(Catalog::optionRollsFor($def), 'kind');
            $has = in_array(Catalog::OPTION_SEAM, $kinds, true);

            $this->assertSame(
                $slot !== '' && (Catalog::skillForSlot($slot) !== null || $slot === 'gloves'),
                $has,
                "{$key} disagrees about whether it can favour a seam",
            );
        }
    }

    /**
     * §4.0 -- and what a glove offers is the GATHER table, which is a different
     * list: the base raw is on it (bare-handed that is the rare find, not the
     * usual one) and the grades above it are not (hands never reach them).
     */
    public function test_a_glove_offers_what_hands_pick_up(): void
    {
        $glove = Catalog::gatherSeamMaterials();

        foreach (Variants::BIOME_VARIANTS as $grades) {
            $materials = array_column($grades, 'material');

            $this->assertContains($materials[0], $glove, 'a glove cannot favour the base raw');
            foreach (array_slice($materials, 1) as $above) {
                $this->assertNotContains($above, $glove, "{$above} is not reachable bare-handed");
            }
        }
    }

    /** And the glove's line bends the gather table, exactly as a tool's does. */
    public function test_a_glove_bends_the_gather_table(): void
    {
        $tile = ['biome' => 'forest', 'variant' => 'forest'];

        $plain = Drops::table(Drops::GATHERING, $tile, 0);
        $lucky = Drops::table(Drops::GATHERING, $tile, 0, false, ['birch_sap' => 0.30]);

        $this->assertEqualsWithDelta($plain['birch_sap'] * 1.30, $lucky['birch_sap'], 0.001);
        // And nothing else on the table moved.
        $this->assertSame($plain['toadstool'], $lucky['toadstool']);
        $this->assertSame($plain['branch'], $lucky['branch']);
    }

    /**
     * §4.0/§7.3 -- on a gather the HANDS are the tool, so a lucky axe counts
     * for nothing.
     *
     * The same sentence §8.0 rule 1 makes about a mine, pointed the other way:
     * a hex is worked with the tool or with the hands and never with both.
     */
    public function test_a_lucky_axe_does_nothing_bare_handed(): void
    {
        $game = app(GameService::class);
        $character = $game->createCharacter(Player::create(['wallet' => '0xhands', 'session_id' => 'hands']));

        CharacterItem::create([
            'character_id' => $character->id,
            'item_key' => 'hewn_axe',
            'durability' => 40,
            'equipped' => true,
            'options' => [['stat' => 'toadstool', 'value' => 0.30, 'kind' => Catalog::OPTION_SEAM]],
        ]);
        CharacterItem::create([
            'character_id' => $character->id,
            'item_key' => 'work_gloves',
            'durability' => 90,
            'equipped' => true,
            'options' => [['stat' => 'birch_sap', 'value' => 0.20, 'kind' => Catalog::OPTION_SEAM]],
        ]);

        $character = $character->fresh();

        // Mining reads the axe and not the glove's gather line...
        $this->assertSame(['toadstool' => 0.30], $game->seamFavour($character, 'woodcutting'));

        // ...and gathering reads the glove and not the axe.
        $this->assertSame(['birch_sap' => 0.20], $game->seamFavour($character, 'woodcutting', true));
    }

    /** §8.0.1 -- three values and not five: ten, twenty, thirty. */
    public function test_the_ladder_is_three_values(): void
    {
        $values = array_values(array_unique(array_values(Balance::OPTION_SEAM_VALUE)));
        sort($values);

        $this->assertSame([0.10, 0.20, 0.30], $values);
    }

    /**
     * §5.3 -- and it bends the weights, which is the whole of what it does.
     *
     * A fifth more of a material that already turns up half the time is a fifth
     * more of HALF, so the favour is applied to the weight rather than to the
     * roll and is worth exactly what it says.
     */
    public function test_a_favoured_grade_gets_its_share_more_weight(): void
    {
        $grades = Variants::BIOME_VARIANTS['forest'];
        $tile = ['biome' => 'forest', 'variant' => $grades[1]['key']];
        $favoured = $grades[1]['material'];

        $plain = Drops::tableFor(Drops::MINING, $tile, $favoured);
        $bent = Drops::tableFor(Drops::MINING, $tile, $favoured, false, [$favoured => 0.30]);

        $this->assertEqualsWithDelta($plain[$favoured] * 1.30, $bent[$favoured], 0.001);

        // And nothing else on the hex moved.
        foreach ($plain as $key => $weight) {
            if ($key !== $favoured) {
                $this->assertSame($weight, $bent[$key], "{$key} moved");
            }
        }
    }

    /**
     * §5.3 -- worth nothing on ground that does not hold the grade.
     *
     * The same rule the upward tail keeps: a line for ironwood is a line for
     * ironwood, not a line for wishing.
     */
    public function test_it_is_worth_nothing_where_the_grade_is_not(): void
    {
        $grades = Variants::BIOME_VARIANTS['forest'];
        $plain = ['biome' => 'forest', 'variant' => $grades[0]['key']];
        $top = $grades[3]['material'];

        $before = Drops::tableFor(Drops::MINING, $plain, $grades[0]['material']);
        $after = Drops::tableFor(Drops::MINING, $plain, $grades[0]['material'], false, [$top => 0.30]);

        $this->assertSame($before, $after);
        $this->assertArrayNotHasKey($top, $after);
    }

    /** §8 rule 1 -- an axe's line does nothing to a seam. */
    public function test_the_favour_is_locked_to_the_tools_own_line(): void
    {
        $game = app(GameService::class);
        $character = $game->createCharacter(Player::create(['wallet' => '0xseam', 'session_id' => 'seam']));

        CharacterItem::create([
            'character_id' => $character->id,
            'item_key' => 'hewn_axe',
            'durability' => 40,
            'equipped' => true,
            'options' => [['stat' => 'ironwood', 'value' => 0.30, 'kind' => Catalog::OPTION_SEAM]],
        ]);

        $character = $character->fresh();

        $this->assertSame(['ironwood' => 0.30], $game->seamFavour($character, 'woodcutting'));
        $this->assertSame([], $game->seamFavour($character, 'mining'));
        $this->assertSame([], $game->seamFavour($character, null));
    }
}
