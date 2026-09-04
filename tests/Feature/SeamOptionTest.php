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

    /** §5.3 -- three grades above the base, per line, and never the base. */
    public function test_every_line_offers_its_three_non_common_grades(): void
    {
        foreach (Catalog::TOOL_SLOT_SKILL as $slot => $line) {
            $materials = Catalog::seamMaterialsForSlot($slot);

            $this->assertCount(3, $materials, "{$line} does not offer three grades");

            foreach ($materials as $key) {
                $this->assertArrayHasKey($key, Catalog::materials(), "{$key} is not a material");
            }

            // The base grade is what a hex mostly gives anyway; a line
            // promising more of it would read as luck and would not be.
            $base = Catalog::SKILLS[$line]['material'] ?? null;
            $this->assertNotContains($base, $materials, "{$line} offers its own base grade");
        }
    }

    /** Only a gathering tool carries it: nothing worn works a seam. */
    public function test_only_a_gathering_tool_may_roll_one(): void
    {
        foreach (Catalog::items() as $key => $def) {
            $slot = $def['slot'] ?? null;
            $stats = array_column(Catalog::optionRollsFor($def), 'kind');
            $has = in_array(Catalog::OPTION_SEAM, $stats, true);

            $this->assertSame(
                $slot !== null && Catalog::skillForSlot((string) $slot) !== null,
                $has,
                "{$key} disagrees about whether it can favour a seam",
            );
        }
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
