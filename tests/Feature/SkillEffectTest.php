<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Formulas;
use App\Game\GameException;
use App\Game\GameService;
use App\Game\Jobs;
use App\Models\Character;
use App\Models\CharacterNode;
use App\Models\GameJob;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §7.4.3 -- every kind of node, proved to do something.
 *
 * This file exists because of what it replaced. `unlock` was a third of some
 * trees and was collected into an array nothing ever read: a hundred nodes
 * bought with the scarcest thing a character has, and not one of them changed
 * an outcome. A shape test cannot catch that -- the data was well-formed the
 * whole time -- so each kind is exercised here against the call site it claims.
 */
final class SkillEffectTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        config(['game.packs' => false]);

        $this->game = app(GameService::class);
        $player = Player::create(['wallet' => '0xskill', 'session_id' => 'skill']);
        $this->character = $this->game->createCharacter($player);
    }

    /** Every node of one kind in one tree, bought outright. */
    private function grantKind(string $job, string $kind): int
    {
        $granted = 0;

        foreach (Jobs::nodesFor($job) as $key => $node) {
            if ($node['effect']['kind'] !== $kind) {
                continue;
            }

            CharacterNode::create(['character_id' => $this->character->id, 'node_key' => $key]);
            $granted++;
        }

        $this->character = $this->character->fresh();

        return $granted;
    }

    private function give(array $stock): void
    {
        $add = new \ReflectionMethod($this->game, 'addMaterial');
        foreach ($stock as $key => $qty) {
            $add->invoke($this->game, $this->character->fresh(), $key, $qty);
        }
        $this->character = $this->character->fresh();
    }

    private function invoke(string $method, array $args): mixed
    {
        return (new \ReflectionMethod($this->game, $method))->invoke($this->game, ...$args);
    }

    /** Stand at the woodcutting village §5.4 guarantees every spawn. */
    private function standAtVillage(): array
    {
        $range = Balance::SPAWN_VILLAGE_RADIUS;

        for ($dc = -$range; $dc <= $range; $dc++) {
            for ($dr = -$range; $dr <= $range; $dr++) {
                $s = \App\Game\WorldGen::settlementAt(
                    (int) $this->character->col + $dc,
                    (int) $this->character->row + $dr,
                );

                if ($s && in_array('woodcutting', $s['lines'], true)) {
                    $this->character->update(['col' => $s['col'], 'row' => $s['row']]);
                    $this->character->refresh();

                    return $s;
                }
            }
        }

        $this->fail('spawn guarantee broken: no woodcutting village in spawn radius');
    }

    /**
     * §7.4.3 -- a gathering tree spares the line's tool, and only that line's.
     *
     * Rolled per mine rather than shaved off it, because DRAIN_PER_MINE is one
     * point and a fraction of one point is nothing a player could read off the
     * item.
     */
    public function test_a_gathering_tree_spares_the_tool_it_swings(): void
    {
        $this->assertGreaterThan(0, $this->grantKind('woodcutting', 'toolWear'));

        $spared = 0;
        $paid = 0;

        for ($i = 1; $i <= 200; $i++) {
            $job = new GameJob(['skill_key' => 'woodcutting', 'started_at' => $i * 7919]);
            $job->id = $i;

            $drain = $this->invoke('tripDrain', [$this->character->fresh(), $job, 'woodcutting']);
            $drain === 0 ? $spared++ : $paid++;

            // The other four tools idle, §8 rule 2, and so does the tree.
            $this->assertSame(
                Balance::DRAIN_PER_MINE,
                $this->invoke('tripDrain', [$this->character->fresh(), $job, 'mining']),
                'a woodcutting node paid out down a mine',
            );
        }

        $this->assertGreaterThan(0, $spared, 'a maxed tree never spared a single mine');
        $this->assertGreaterThan($spared, $paid, 'the tool is spared more often than it is worn');
    }

    /**
     * §5.1 + §7.4.3 -- what a gathering tree knows about the ground it works.
     *
     * `seamGrade` is a rolled COUNT of grades rather than a percentage, so what
     * is pinned here is the aggregate and its cap: the roll itself belongs to
     * the mine, and the line-lock is the same one every other tree effect has.
     */
    public function test_a_gathering_tree_reads_the_seam_within_its_cap(): void
    {
        $bare = $this->invoke('jobEffects', [$this->character->fresh(), 'quarrying']);
        $this->assertSame(0.0, (float) $bare['seamGrade']);

        $this->assertGreaterThan(0, $this->grantKind('quarrying', 'seamGrade'));

        $after = (float) $this->invoke('jobEffects', [$this->character->fresh(), 'quarrying'])['seamGrade'];

        $this->assertGreaterThan(0, $after, 'a maxed quarrying tree reads no better');
        $this->assertLessThanOrEqual(Balance::SKILL_SEAM_GRADE_CAP, $after, 'the tree passed its cap');

        // Line-locked like everything else: a quarrying node is worth nothing
        // in a forest.
        $this->assertSame(
            0.0,
            (float) $this->invoke('jobEffects', [$this->character->fresh(), 'woodcutting'])['seamGrade'],
        );
    }

    /**
     * §6.2 + §7.4.3 -- presence is worth more to somebody who knows the line,
     * and leaving gives back exactly what standing there bought.
     */
    public function test_a_processing_tree_is_worth_more_while_you_stand_there(): void
    {
        $settlement = $this->standAtVillage();
        $this->give(['wood' => 40]);

        $this->assertGreaterThan(0, $this->grantKind('sawyer', 'presence'));

        $bonus = $this->invoke('presenceBonus', [$this->character->fresh(), 'woodcutting']);
        $this->assertGreaterThan(Balance::PRESENCE_SPEED_BONUS, $bonus);

        $this->character->update(['presence_settlement_id' => $settlement['id']]);

        $job = $this->game->startProcessing($this->character->fresh(), $settlement['id'], 'planks', 1);
        $this->assertTrue((bool) $job->presence);
        $this->assertEqualsWithDelta($bonus, (float) $job->payload['presenceBonus'], 1e-9);

        $flat = Formulas::processingTime(
            \App\Game\Catalog::recipe('planks')['baseSeconds'],
            $settlement['tier'],
            true,
            0.0,
        );

        $this->assertLessThan(
            $flat,
            (int) round(($job->ends_at - $job->started_at) / 1000),
            'a maxed Sawyer standing at the pit was worth nothing extra',
        );

        // And walking out charges back exactly the discount that was given.
        $ends = (int) $job->ends_at;
        $this->invoke('leavePresence', [$this->character->fresh()]);
        $left = GameJob::find($job->id);
        $this->assertGreaterThan($ends, (int) $left->ends_at);
    }

    /** §7.4.3 -- and the capstone keeps a second run going. */
    public function test_a_run_slot_node_keeps_two_pits_going(): void
    {
        $settlement = $this->standAtVillage();
        $this->give(['wood' => 60]);

        $this->game->startProcessing($this->character->fresh(), $settlement['id'], 'planks', 1);

        try {
            $this->game->startProcessing($this->character->fresh(), $settlement['id'], 'planks', 1);
            $this->fail('a second run was allowed with no tree behind it');
        } catch (GameException $e) {
            $this->assertSame('busy', $e->errorCode);
        }

        $this->assertGreaterThan(0, $this->grantKind('sawyer', 'runSlot'));

        $second = $this->game->startProcessing($this->character->fresh(), $settlement['id'], 'planks', 1);
        $this->assertSame('processing', $second->kind);
        $this->assertSame(2, $this->character->fresh()->jobs()->where('kind', 'processing')->count());
    }

    /**
     * §7.4.3 -- the consumable bench owns three things, because a potion has no
     * durability and no rolled line for the other two to land on.
     */
    public function test_the_consumable_bench_brews_more_and_holds_more(): void
    {
        $this->standAtVillage();

        $recipe = null;
        foreach (\App\Game\Catalog::items() as $key => $def) {
            if (! empty($def['consumable']) && ($def['rarity'] ?? '') === 'common' && isset($def['inputs'])) {
                $recipe = [$key, $def];
                break;
            }
        }
        $this->assertNotNull($recipe, 'no common draft to brew');
        [$key, $def] = $recipe;

        $this->give(array_map(fn (int $q) => $q * 8, $def['inputs']));

        $batch = $this->grantKind('alchemist', 'batch');
        $stack = $this->grantKind('alchemist', 'stackCap');
        $this->assertGreaterThan(0, $batch);
        $this->assertGreaterThan(0, $stack);

        $job = $this->game->startCraft($this->character->fresh(), $key);
        $job->update(['ends_at' => $this->game->now() - 1]);
        $made = $this->game->collectJob($this->character->fresh(), $job->id)['made'];

        $this->assertTrue($made['consumable']);
        $this->assertGreaterThanOrEqual(1 + $batch, $made['quantity'], 'a maxed rack brewed one flask');
        $this->assertSame($made['quantity'], $this->game->heldConsumable($this->character->fresh(), $key));

        // And the shelf that holds them is deeper than the flat cap.
        $effects = $this->invoke('jobEffects', [$this->character->fresh(), 'alchemist']);
        $this->assertGreaterThan(0, $effects['stackCap']);
        $this->assertLessThanOrEqual(Balance::SKILL_STACK_CAP, $effects['stackCap']);
    }

    /**
     * §8.0.1 -- a maker's tree reaches deeper into the bag a line is drawn
     * from. It never reaches past the item's own rarity, which is the rule that
     * keeps the ladder a ladder.
     */
    public function test_option_tier_draws_from_a_deeper_bag_and_never_past_the_rung(): void
    {
        $def = \App\Game\Catalog::item('ironwood_axe');
        $this->assertNotNull($def);

        $plain = 0.0;
        $upgraded = 0.0;

        for ($seed = 1; $seed <= 300; $seed++) {
            foreach (Formulas::rollOptions($def, $seed, 0, 0.0) as $line) {
                $plain += (float) $line['value'];
            }
            foreach (Formulas::rollOptions($def, $seed, 0, 1.0) as $line) {
                $upgraded += (float) $line['value'];
            }
        }

        $this->assertGreaterThan($plain, $upgraded, 'a deeper bag rolled no better');

        // A common item has one tier to draw from, so the upgrade has nowhere
        // to go and must change nothing at all.
        $common = \App\Game\Catalog::item('stone_axe');
        for ($seed = 1; $seed <= 50; $seed++) {
            $this->assertEquals(
                Formulas::rollOptions($common, $seed, 1, 0.0),
                Formulas::rollOptions($common, $seed, 1, 1.0),
                'an upgrade reached past the rung it was rolled at',
            );
        }
    }

    /** §7.4 -- a battle tree is scoped by the family in the slot, not the job. */
    public function test_a_battle_tree_only_pays_out_through_its_own_family(): void
    {
        $this->assertGreaterThan(0, $this->grantKind('runecaster', 'weaponWear'));
        $this->assertGreaterThan(0, $this->grantKind('runecaster', 'goldFind'));
        $this->assertGreaterThan(0, $this->grantKind('runecaster', 'lootOption'));

        $tree = $this->invoke('battleTree', [$this->character->fresh(), 'focus']);
        $this->assertGreaterThan(0, $tree['weaponWear']);
        $this->assertGreaterThan(0, $tree['gold']);
        $this->assertGreaterThan(0, $tree['loot']);

        $this->assertLessThanOrEqual(Balance::SKILL_WEAPON_WEAR_CAP, $tree['weaponWear']);
        $this->assertLessThanOrEqual(Balance::SKILL_GOLD_FIND_CAP, $tree['gold']);
        $this->assertLessThanOrEqual(Balance::SKILL_LOOT_OPTION_CAP, $tree['loot']);

        foreach (['shield', 'sword'] as $family) {
            $other = $this->invoke('battleTree', [$this->character->fresh(), $family]);
            $this->assertSame(0.0, $other['weaponWear'], "a runecaster node paid out with a {$family}");
            $this->assertSame(0.0, $other['gold']);
            $this->assertSame(0.0, $other['loot']);
        }

        $this->assertSame(
            ['attack' => 0, 'defense' => 0, 'wear' => 0.0, 'weaponWear' => 0.0, 'gold' => 0.0, 'loot' => 0.0],
            $this->invoke('battleTree', [$this->character->fresh(), null]),
        );
    }
}
