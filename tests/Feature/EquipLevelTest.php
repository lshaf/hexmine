<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Catalog;
use App\Game\GameException;
use App\Game\GameService;
use App\Game\Jobs;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterJob;
use App\Models\CharacterSkill;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §7.1/§8.0 -- a rung has a level on it, and there is no jumping it.
 */
final class EquipLevelTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = app(GameService::class);
    }

    /**
     * §12 -- the opening arc is worked in common gear at level 1, so common
     * must never be gated. A soft-locked tutorial is the one failure this rule
     * could actually cause.
     */
    public function test_common_is_wearable_from_the_first_level(): void
    {
        $this->assertSame(1, Balance::equipLevel('common'));
        $this->assertSame(1, Balance::equipJobLevel('common'));
    }

    /** And every rung above it is strictly later than the one under it. */
    public function test_the_gates_climb_with_the_ladder(): void
    {
        $seen = 0;
        foreach (Balance::RARITIES as $rarity) {
            $need = Balance::equipLevel($rarity);
            $this->assertGreaterThan($seen, $need, "{$rarity} did not climb");
            $seen = $need;
        }

        // §7.4.4's cap. A gate nobody can reach is a rung nobody can wear.
        $this->assertLessThanOrEqual(100, $seen);
    }

    /**
     * And so does the job ladder, on its own much shorter scale.
     *
     * A job stops at JOB_MAX_LEVEL where a career runs to 100, so the two
     * ladders cannot be one table -- and the top of this one has to sit inside
     * that cap, or a tool nobody can carry is a rung nobody can craft toward.
     */
    public function test_the_job_gates_climb_and_fit_inside_a_job(): void
    {
        $seen = 0;
        foreach (Balance::RARITIES as $rarity) {
            $need = Balance::equipJobLevel($rarity);
            $this->assertGreaterThan($seen, $need, "{$rarity} did not climb");
            $seen = $need;
        }

        $this->assertLessThanOrEqual(Balance::JOB_MAX_LEVEL, $seen);
    }

    /**
     * §7.1 -- and which of the two a piece reads is decided by what it is FOR.
     *
     * The five tools answer to their own line and the weapon to its family's
     * battle job (§9.5.4); armor, boots and gloves answer to neither, so they
     * keep the career's gate. A slot quietly falling on the wrong side of that
     * is the whole failure this rule can cause, and nothing else would notice.
     */
    public function test_the_gate_job_follows_the_slot(): void
    {
        $this->assertSame('woodcutting', Catalog::equipGateJob(Catalog::item('stone_axe')));
        $this->assertSame('mining', Catalog::equipGateJob(Catalog::item('mythril_pickaxe')));

        foreach (['armor', 'boots', 'gloves'] as $slot) {
            $def = ['slot' => $slot, 'rarity' => 'epic'];
            $this->assertNull(Catalog::equipGateJob($def), "{$slot} picked up a job gate");
        }

        foreach (Catalog::BATTLE_JOB_FOR_FAMILY as $family => $job) {
            $this->assertSame($job, Catalog::equipGateJob(['slot' => 'weapon', 'family' => $family]));
        }
    }

    /** §7.1 -- and a job under the gate is refused at the belt. */
    public function test_a_rung_above_your_job_is_refused(): void
    {
        [$character, $item] = $this->owning('mythril_pickaxe');

        $this->assertSame('epic', Catalog::item('mythril_pickaxe')['rarity']);

        // A long career buys nothing here, which is the whole of the change:
        // an epic pickaxe wants a miner, not somebody who has walked far.
        $character->update(['level' => 100]);

        try {
            $this->game->equipItem($character->fresh(), $item->id);
            $this->fail('an epic went on at mining 1');
        } catch (GameException $e) {
            $this->assertSame('level', $e->errorCode);
            // The refusal names the number AND the job, because a wall with no
            // figure on it is a bug as far as anybody reading it can tell --
            // and a figure with no job on it would be held up against the
            // wrong ladder.
            $this->assertStringContainsString((string) Balance::equipJobLevel('epic'), $e->getMessage());
            $this->assertStringContainsString('Mining', $e->getMessage());
        }

        $this->assertFalse((bool) $item->fresh()->equipped);
    }

    /** And goes on the moment the JOB is there, with nothing else changed. */
    public function test_the_same_piece_goes_on_at_the_gate(): void
    {
        [$character, $item] = $this->owning('mythril_pickaxe');

        $character->skills()->where('skill_key', 'mining')
            ->update(['level' => Balance::equipJobLevel('epic')]);

        $this->game->equipItem($character->fresh(), $item->id);

        $this->assertTrue((bool) $item->fresh()->equipped);
    }

    /**
     * §9.5.4 -- and a weapon reads the family in the slot, not the line.
     *
     * A maxed woodcutter is still a beginner with a sword, which is the point
     * of gating on the job that swings the thing: the ladder cannot be reached
     * around by work the piece has nothing to do with.
     */
    public function test_a_weapon_reads_its_own_battle_job(): void
    {
        $key = $this->firstItemWhere(
            static fn (array $d): bool => ($d['slot'] ?? null) === 'weapon'
                && ($d['family'] ?? null) === 'sword'
                && ($d['rarity'] ?? null) === 'rare',
        );

        [$character, $item] = $this->owning($key);

        $character->skills()->update(['level' => Balance::SKILL_MAX_LEVEL]);
        $character->update(['level' => 100]);

        try {
            $this->game->equipItem($character->fresh(), $item->id);
            $this->fail('a rare sword went on at swordhand 1');
        } catch (GameException $e) {
            $this->assertSame('level', $e->errorCode);
            $this->assertStringContainsString('Swordhand', $e->getMessage());
        }

        $character->jobLevels()->where('job_key', 'swordhand')
            ->update(['level' => Balance::equipJobLevel('rare')]);

        $this->game->equipItem($character->fresh(), $item->id);
        $this->assertTrue((bool) $item->fresh()->equipped);
    }

    /**
     * §7.1 -- worn gear keeps the career's own gate, because it answers to no
     * job at all. A coat is not made better by a good miner.
     */
    public function test_worn_gear_still_reads_the_career(): void
    {
        $key = $this->firstItemWhere(
            static fn (array $d): bool => ($d['slot'] ?? null) === 'armor'
                && ($d['rarity'] ?? null) === 'epic',
        );

        [$character, $item] = $this->owning($key);

        // Every job maxed, and it buys nothing: this rung wants the career.
        $character->skills()->update(['level' => Balance::SKILL_MAX_LEVEL]);
        $character->jobLevels()->update(['level' => Balance::JOB_MAX_LEVEL]);

        try {
            $this->game->equipItem($character->fresh(), $item->id);
            $this->fail('an epic coat went on at level 1');
        } catch (GameException $e) {
            $this->assertSame('level', $e->errorCode);
            $this->assertStringContainsString((string) Balance::equipLevel('epic'), $e->getMessage());
        }

        $character->update(['level' => Balance::equipLevel('epic')]);

        $this->game->equipItem($character->fresh(), $item->id);
        $this->assertTrue((bool) $item->fresh()->equipped);
    }

    /**
     * §8.1 rule 4 -- F2P viability. Every rung below unique stays reachable by
     * crafting, so a gate may delay a rung and may never put one out of reach:
     * the top gate has to sit inside the level cap with room to play in.
     */
    public function test_the_ladder_leaves_a_career_room_above_it(): void
    {
        $this->assertLessThan(
            100,
            Balance::equipLevel('unique'),
            'the top rung is gated at or past the end of a career',
        );

        $this->assertLessThan(
            Balance::JOB_MAX_LEVEL,
            Balance::equipJobLevel('unique'),
            'the top rung is gated at or past the end of a job',
        );
    }

    /**
     * §16 -- and the client's copy says the same thing. Both copies.
     *
     * The gate is enforced on the server and DRAWN on the client -- a chip on
     * every piece of gear, ember when it cannot be met -- so the two carry the
     * same tables. A drift here is the worst kind: the shop would offer a rung
     * the belt then refuses, and nothing would fail until a player tapped it.
     *
     * Read out of the named block rather than swept for as a substring, because
     * there are two ladders now and they share figures: `rare: 8` in one is
     * `uncommon: 8` in the other, and a loose search would call a swapped pair
     * a match.
     */
    public function test_the_client_carries_the_same_ladders(): void
    {
        $mirror = file_get_contents(base_path('resources/js/game/balance.ts'));

        foreach (['EQUIP_LEVEL' => Balance::EQUIP_LEVEL, 'EQUIP_JOB_LEVEL' => Balance::EQUIP_JOB_LEVEL] as $name => $ladder) {
            $this->assertSame($ladder, $this->tsLadder($mirror, $name), "balance.ts disagrees with Balance::{$name}");
        }
    }

    /**
     * And so does the generator, which is where a monster's level is decided.
     *
     * §9.5.2 quotes a monster on the JOB ladder, because a battle job level is
     * what bounds the rung a fighter may carry (§7.1). gen_monsters.py holds
     * its own literal copy to do it, and that copy is the one place the two
     * ladders meet: if it drifts, every monster in the game is quoted on a
     * scale nothing else uses and no test on either side would notice.
     */
    public function test_the_monster_generator_carries_the_job_ladder(): void
    {
        $gen = file_get_contents(base_path('scripts/gen_monsters.py'));

        foreach (Balance::EQUIP_JOB_LEVEL as $rarity => $level) {
            if ($rarity === 'unique') {
                continue;  // never a monster band: nothing is crafted at it (§8.0).
            }

            $this->assertStringContainsString(
                "'{$rarity}': {$level}",
                $gen,
                "gen_monsters.py disagrees with Balance::EQUIP_JOB_LEVEL['{$rarity}']",
            );
        }
    }

    /**
     * Pull one `export const NAME: Record<string, number> = { ... }` out of the
     * mirror as a real array, so the comparison is between two ladders rather
     * than between a table and a bag of substrings.
     *
     * @return array<string,int>
     */
    private function tsLadder(string $mirror, string $name): array
    {
        $this->assertSame(
            1,
            preg_match('/export const '.$name.'[^=]*= \{(.*?)\}/s', $mirror, $m),
            "balance.ts has no {$name} block",
        );

        preg_match_all('/(\w+):\s*(\d+)/', $m[1], $rows, PREG_SET_ORDER);

        return array_combine(
            array_column($rows, 1),
            array_map('intval', array_column($rows, 2)),
        );
    }

    /** The first catalog key matching a shape, so a test names a rule not a row. */
    private function firstItemWhere(callable $matches): string
    {
        foreach (Catalog::items() as $key => $def) {
            if ($matches($def)) {
                return $key;
            }
        }

        $this->fail('the catalog has nothing of that shape');
    }

    /** @return array{0:Character,1:CharacterItem} */
    private function owning(string $key): array
    {
        $player = Player::create(['wallet' => '0x'.bin2hex(random_bytes(8))]);
        $character = Character::create([
            'player_id' => $player->id,
            'name' => 'Gated',
            'col' => 0, 'row' => 0, 'level' => 1, 'xp' => 0, 'gold' => 0,
        ]);

        // §7.4 -- the rows createCharacter seeds, because a gate is now read off
        // one of them. A character with no skill row and no job row would be
        // level 1 at everything by default, which is right, and would leave
        // nothing for a test to raise.
        foreach (Catalog::SKILLS as $skill) {
            CharacterSkill::create([
                'character_id' => $character->id,
                'skill_key' => $skill,
                'level' => 1,
                'xp' => 0,
            ]);
        }

        foreach (Jobs::JOBS as $job => $def) {
            if ($def['kind'] === Jobs::GATHERING) {
                continue;
            }

            CharacterJob::create([
                'character_id' => $character->id,
                'job_key' => $job,
                'level' => 1,
                'xp' => 0,
            ]);
        }

        $item = CharacterItem::create([
            'character_id' => $character->id,
            'item_key' => $key,
            'durability' => Catalog::item($key)['maxDurability'],
            'equipped' => false,
        ]);

        return [$character->fresh(), $item];
    }
}
