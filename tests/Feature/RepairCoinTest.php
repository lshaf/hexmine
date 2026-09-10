<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Catalog;
use App\Game\Formulas;
use App\Game\GameException;
use App\Game\GameService;
use App\Game\WorldGen;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * §8.2 -- buying the parts for a mend over the counter.
 *
 * A settlement reaches a material TIER and sells you the bill as far as that
 * goes: a village keeps a rack of raw, a city and a capital have the refined
 * stock as well, and nothing above tier 2 is ever payable in gold anywhere.
 * That last one is the §2 rule the whole shape exists for -- a capped Tier 3
 * with a gold price on it is a capped rare turned into uncapped coin.
 */
final class RepairCoinTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    private Character $character;

    /** @var array<string,array<string,mixed>> tier -> a settlement of that tier */
    private static array $found = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['game.packs' => false]);

        $this->game = app(GameService::class);
        $this->character = $this->game->createCharacter(
            Player::create(['wallet' => '0xcoin', 'session_id' => 'coin']),
        );
    }

    // ------------------------------------------------------------- the split

    /**
     * A village keeps a rack of raw and nothing behind it.
     *
     * The refined half of the bill still has to have been carried in, which is
     * what makes a city worth walking to for a mend as well as for a bench.
     */
    public function test_a_village_sells_the_raw_and_not_the_refined(): void
    {
        $cost = $this->fullMend('iron_pickaxe');
        $split = Formulas::repairCoinSplit($cost, Balance::REPAIR_COIN_TIER['village']);

        $this->assertNotSame([], $split['coin'], 'a village sells nothing at all');

        foreach ($split['coin'] as $key => $qty) {
            $this->assertLessThanOrEqual(1, Catalog::material($key)['tier'], "{$key} is not raw");
        }
        foreach ($split['materials'] as $key => $qty) {
            $this->assertGreaterThan(1, Catalog::material($key)['tier'], "{$key} should have been sold");
        }
    }

    /** And a city reaches the refined stock, which is the whole of the tier story. */
    public function test_a_city_reaches_the_refined_stock(): void
    {
        $cost = $this->fullMend('iron_pickaxe');

        $village = Formulas::repairCoinSplit($cost, Balance::REPAIR_COIN_TIER['village']);
        $city = Formulas::repairCoinSplit($cost, Balance::REPAIR_COIN_TIER['city']);

        $this->assertSame([], $city['materials'], 'a city left refined stock on the bill');
        $this->assertGreaterThan(
            Formulas::repairCoinPrice($village['coin']),
            Formulas::repairCoinPrice($city['coin']),
            'the city counter is no dearer than the village one',
        );
    }

    /**
     * §2 -- and NO counter ever sells a capped rare, at any tier, on any piece.
     *
     * This is the rule the whole split exists for. §5.3 gives a Tier 3 no price
     * at all because the trader will not touch one, so a gold figure on a mend
     * that wanted ironwood would route straight around the per-wallet cap --
     * which is the sentence §8.2 already writes about the resale counter, said
     * about repair. A weight tweak nobody read as one would open it, so the
     * sweep is over every craftable piece rather than over a sample.
     */
    public function test_no_counter_anywhere_sells_a_capped_rare(): void
    {
        $checked = 0;

        foreach (Catalog::items() as $key => $def) {
            if (! isset($def['inputs'], $def['maxDurability'])) {
                continue;
            }

            $cost = $this->fullMend($key);

            foreach (Balance::REPAIR_COIN_TIER as $tier => $reach) {
                $split = Formulas::repairCoinSplit($cost, $reach);

                foreach ($split['coin'] as $part => $qty) {
                    $this->assertLessThanOrEqual(
                        2,
                        Catalog::material($part)['tier'],
                        "a {$tier} counter sold {$part} toward a {$key}",
                    );
                }
            }

            $checked++;
        }

        $this->assertGreaterThan(40, $checked, 'the sweep found almost nothing to check');
    }

    /**
     * §8.2 -- the counter charges more than it pays, on every bill it will take.
     *
     * The markup is above 1 by rule rather than by tuning, and this is what it
     * buys: the NPC pays 1x for a material and asks 1.5x for it, so gathering
     * the parts is always strictly better value than buying them and there is
     * no gather-sell-then-buy loop that beats mending directly.
     */
    public function test_the_counter_never_pays_more_than_it_charges(): void
    {
        foreach (Catalog::items() as $key => $def) {
            if (! isset($def['inputs'], $def['maxDurability'])) {
                continue;
            }

            $cost = $this->fullMend($key);

            foreach (Balance::REPAIR_COIN_TIER as $tier => $reach) {
                $coin = Formulas::repairCoinSplit($cost, $reach)['coin'];
                if ($coin === []) {
                    continue;
                }

                $this->assertGreaterThan(
                    Formulas::materialWorth($coin),
                    Formulas::repairCoinPrice($coin),
                    "a {$tier} counter sells the parts for a {$key} at or under what it pays for them",
                );
            }
        }
    }

    // -------------------------------------------------------------- the mend

    /** §8.2 -- gold for the half it stocks, the bag for the half it does not. */
    public function test_a_coin_mend_takes_gold_and_only_the_parts_it_did_not_sell(): void
    {
        $this->standAt('village');

        $item = $this->worn('iron_pickaxe');
        $cost = Formulas::repairCost(
            Catalog::item('iron_pickaxe'),
            $item->maxDurability() - $item->durability,
            $item->maxDurability(),
        );
        $split = Formulas::repairCoinSplit($cost, Balance::REPAIR_COIN_TIER['village']);
        $gold = Formulas::repairCoinPrice($split['coin']);

        // Everything the bill wants, so what is left afterwards says which half
        // was actually spent.
        $this->give(array_map(static fn () => 200, $cost));
        $this->purse($gold + 500);

        $this->game->repairItem($this->character->fresh(), $item->id, true);

        $this->assertSame($item->maxDurability(), $item->fresh()->durability);
        $this->assertSame(500, (int) $this->character->fresh()->gold, 'the counter charged the wrong price');

        foreach ($split['coin'] as $key => $qty) {
            $this->assertSame(200, $this->held($key), "{$key} was taken as well as bought");
        }
        foreach ($split['materials'] as $key => $qty) {
            $this->assertSame(200 - $qty, $this->held($key), "{$key} was not taken out of the bag");
        }
    }

    /**
     * §8.2 -- and it still teaches, because the gold bought the PARTS.
     *
     * A trader is not a bench, and a trader selling you four planks and
     * standing back is not one either: the mending is still yours. That is what
     * tells this apart from the branch above it, where the NPC mends a basic
     * for you and you learn nothing.
     */
    public function test_a_coin_mend_still_teaches_the_bench(): void
    {
        $this->standAt('village');

        $item = $this->worn('hewn_axe');
        $this->give(array_map(static fn () => 200, $this->fullMend('hewn_axe')));
        $this->purse(5000);

        $out = $this->game->repairItem($this->character->fresh(), $item->id, true);

        $this->assertSame('smith', $out['job']);
        $this->assertGreaterThan(0, $out['jobXp'], 'buying the parts stopped the mend teaching');
        $this->assertGreaterThan(0, $out['gold'], 'the mend reported no price');
    }

    /**
     * §8.2 -- and a mend of ANY kind happens where the bench is.
     *
     * The same anvil, the same job and a bill in the same materials, and it
     * teaches the craft job that could have made the piece -- which is the
     * whole argument, and it was being made from the middle of a forest. The
     * coin path always asked for a counter; the material path asked for
     * nothing, which left the one place a mend is actually done the one place
     * it was not offered.
     */
    public function test_a_mend_of_any_kind_is_refused_in_the_field(): void
    {
        $this->assertNull(
            $this->game->currentSettlement($this->character),
            'this test wants open country',
        );

        $item = $this->worn('hewn_axe');
        $this->give(array_map(static fn () => 200, $this->fullMend('hewn_axe')));

        try {
            $this->game->repairItem($this->character->fresh(), $item->id);
            $this->fail('an axe was mended in a field');
        } catch (GameException $e) {
            $this->assertSame('not_at_settlement', $e->errorCode);
        }

        // And nothing was spent finding that out (§8.4).
        $this->assertSame(1, $item->fresh()->durability);
        $this->assertSame(200, $this->held('wood'));
    }

    /** §6 -- there is nobody out here to buy parts from. */
    public function test_a_coin_mend_is_refused_in_the_field(): void
    {
        $this->assertNull(
            $this->game->currentSettlement($this->character),
            'this test wants open country',
        );

        $item = $this->worn('hewn_axe');
        $this->give(array_map(static fn () => 200, $this->fullMend('hewn_axe')));
        $this->purse(5000);

        $this->expectException(GameException::class);
        $this->game->repairItem($this->character->fresh(), $item->id, true);
    }

    /** §8.2 -- refused before anything is spent, gold included. */
    public function test_a_coin_mend_short_of_gold_spends_nothing(): void
    {
        $this->standAt('village');

        $item = $this->worn('iron_pickaxe');
        $this->give(array_map(static fn () => 200, $this->fullMend('iron_pickaxe')));
        $this->purse(1);

        try {
            $this->game->repairItem($this->character->fresh(), $item->id, true);
            $this->fail('a mend went through with a penny in the purse');
        } catch (GameException $e) {
            $this->assertSame('no_gold', $e->errorCode);
        }

        $this->assertSame(1, (int) $this->character->fresh()->gold);
        $this->assertSame(1, $item->fresh()->durability, 'the piece was mended anyway');
        $this->assertSame(200, $this->held('hematite'), 'materials went for a refused mend');
    }

    /**
     * §16 -- and the client's copy says the same thing.
     *
     * The plate draws the price and the button carries it, both off a mirror of
     * this table. A drift would put a figure on a button the server then
     * charges differently for, which is the worst kind: nothing fails until
     * somebody presses it.
     */
    public function test_the_client_carries_the_same_counter(): void
    {
        $mirror = file_get_contents(base_path('resources/js/game/balance.ts'));

        $pairs = [];
        foreach (Balance::REPAIR_COIN_TIER as $tier => $reach) {
            $pairs[] = "{$tier}: {$reach}";
        }

        $this->assertStringContainsString(
            'repairCoinTier: { '.implode(', ', $pairs).' }',
            $mirror,
            'balance.ts disagrees with Balance::REPAIR_COIN_TIER',
        );

        $this->assertStringContainsString(
            'repairCoinMarkup: '.Balance::REPAIR_COIN_MARKUP,
            $mirror,
            'balance.ts disagrees with Balance::REPAIR_COIN_MARKUP',
        );
    }

    // ------------------------------------------------------------------ kit

    /** What a full mend of this piece costs in materials. */
    private function fullMend(string $key): array
    {
        $def = Catalog::item($key);
        $max = (int) $def['maxDurability'];

        return Formulas::repairCost($def, $max - 1, $max);
    }

    /** The piece, worn down to one point. */
    private function worn(string $key): CharacterItem
    {
        return CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => $key,
            'durability' => 1,
        ]);
    }

    private function give(array $stock): void
    {
        $add = new ReflectionMethod($this->game, 'addMaterial');
        foreach ($stock as $key => $qty) {
            $add->invoke($this->game, $this->character->fresh(), $key, $qty);
        }
        $this->character = $this->character->fresh();
    }

    private function held(string $key): int
    {
        $held = new ReflectionMethod($this->game, 'held');

        return (int) $held->invoke($this->game, $this->character->fresh(), $key);
    }

    private function purse(int $gold): void
    {
        $this->character->gold = $gold;
        $this->character->save();
    }

    /** Stand on a settlement of this tier. Searched, like everything else. */
    private function standAt(string $tier): array
    {
        if (isset(self::$found[$tier])) {
            $s = self::$found[$tier];
            $this->character->col = (int) $s['col'];
            $this->character->row = (int) $s['row'];
            $this->character->save();

            return $s;
        }

        $radius = Balance::mapRadius();
        $fromCol = $tier === 'capital' ? 0 : (int) $this->character->col;
        $fromRow = $tier === 'capital' ? 0 : (int) $this->character->row;

        for ($ring = 1; $ring < 2 * $radius; $ring++) {
            for ($dc = -$ring; $dc <= $ring; $dc++) {
                for ($dr = -$ring; $dr <= $ring; $dr++) {
                    if (max(abs($dc), abs($dr)) !== $ring) {
                        continue;
                    }

                    $col = $fromCol + $dc;
                    $row = $fromRow + $dr;
                    if (abs($col) > $radius || abs($row) > $radius) {
                        continue;
                    }

                    $s = WorldGen::settlementAt($col, $row);
                    if ($s === null || $s['tier'] !== $tier) {
                        continue;
                    }

                    $this->character->col = (int) $s['col'];
                    $this->character->row = (int) $s['row'];
                    $this->character->save();

                    return self::$found[$tier] = $s;
                }
            }
        }

        $this->fail("no {$tier} anywhere near the spawn");
    }
}
