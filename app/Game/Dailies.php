<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Dailies, §12.2 -- three small tasks a day, and the first gold faucet in the
 * game that never runs out.
 *
 * §12.1 gave the ledger its safety in one sentence: a quest is claimed once and
 * never comes back, so a finite list of one-time payouts is worth exactly as
 * much to a thousand wallets as it is to one. It also said, in as many words,
 * that dailies must not be bolted on to that -- they need their own cap and
 * their own argument. This is both.
 *
 * **The cap is a RATE, not a total.** Three tasks a day, per character, and §7
 * gives a wallet exactly one character. Grinding harder does not produce a
 * fourth: a prospector who plays for ten hours and one who plays for one clear
 * the same three. So the faucet's whole lifetime yield is a function of
 * *wallets multiplied by days* -- and §2 has already priced both ends of that.
 * A wallet costs a one-time mint fee and has to hold a balance for seven
 * continuous days before it can act at all. A farm running a thousand wallets
 * earns a thousand small dailies a day and pays a thousand sybil costs for the
 * privilege, which is exactly the arithmetic §2 exists to make lose.
 *
 * **And every task pays less than the work it asks for.** Forty units of raw
 * fetch about eighty gold at the NPC's own poor rate; the daily that asks for
 * them pays twenty-eight. That is deliberate and it is the second half of the
 * safety: a daily is a *nudge* toward a system you have not touched today, never
 * an income. If one of these ever pays better than selling what it asked you to
 * bring, it has stopped being a daily and become a job.
 *
 * **Gold and only gold**, for the reason §12.1 gives: §3.3 forbids a grind->NFT
 * faucet outright, and gold is the currency that may be inflated precisely
 * because it bridges to nothing external (§3.2).
 *
 * **Nothing here is a new verb either.** Every goal rides a counter §12 already
 * had, fired from the same call sites, so a daily can only ever be advanced by
 * work the server itself witnessed.
 *
 * ------------------------------------------------------------------- the draw
 *
 * **Derived, never stored.** A day nobody has played costs no storage: the three
 * tasks are a hash of `(character, day, lane)`, exactly the way a pack (§9.5.1)
 * and a pocket (§5.7) are hashes of a hex and a time bucket. What gets written
 * down is only what the hash cannot know -- how far along you are, and whether
 * you took the gold.
 *
 * **One task from each of three lanes**, rather than three from one pool. The
 * lanes are the three things a prospector's day is made of, and drawing one of
 * each is what stops a day being three of the same verb:
 *
 * | Lane | What it asks for |
 * |---|---|
 * | `field` | take something out of a hex |
 * | `bench` | turn it into something -- a run, or a craft |
 * | `road` | the walk, and the counter you walk to |
 *
 * **The field task is workable from wherever you are standing, always.** That is
 * the one rule the pool is built around and it is why no daily in this file
 * names a material, a line or a biome. The map takes days to cross (§5.6): a
 * task with a 24-hour clock that wants iron ore, handed to somebody standing in
 * a forest, is not a task but a taunt. So the field lane counts *units*, off any
 * hex, of anything -- and it does not care whether a tool was used, because §7.3
 * says bare hands and a Stone Axe are the same arithmetic at different rungs.
 *
 * The other two lanes can want a settlement, because one of your three never
 * does. A day always has something in it for the hex you are on.
 *
 * ------------------------------------------------------------- and it expires
 *
 * **Only today's work counts.** This is the one place the ledger's rule is
 * reversed: §12.1 credits a quest for work done before it was ever offered,
 * because being handed a task already half done is a better welcome than being
 * told to start again. A daily is the opposite by definition -- a tally that
 * carried over would make "daily" a word for a quest with a slower name -- so
 * progress is keyed to the day and yesterday's haul is yesterday's.
 *
 * Unclaimed gold expires with it. Nothing is owed, nothing accrues, and there is
 * no streak: a streak is a punishment for a day off, and this is an idle game.
 */
final class Dailies
{
    /** A day, before `Balance::scaled()` gets at it. */
    public const DAY_MS = 24 * Balance::HOUR;

    /**
     * The three lanes, in the order they are drawn and shown.
     *
     * `field` is first because it is the one that is always workable from where
     * you are standing, and a list is read from the top.
     */
    public const LANES = ['field', 'bench', 'road'];

    /**
     * The pool, by lane.
     *
     * Shaped exactly like `Quests::DEFS` minus `requires`, because a daily has
     * no chain -- which means `goalLabel` on the client, and the counter in
     * GameService, both work on either without knowing which they hold.
     *
     * A `subject` of null is "any", and in the field lane it is the only legal
     * value: see the note above about a task that names a biome.
     *
     * @var array<string,array<string,array<string,mixed>>>
     */
    public const DEFS = [
        // --------------------------------------------------------------- field
        //
        // Units out of a hex, off any ground, with or without a tool. Nothing
        // here names a material: whatever is under your feet counts.
        'field' => [
            'first_load' => [
                'name' => 'A Load Before Noon',
                'description' => 'Twenty units out of the ground, off any hex you like. What is under your feet is what counts.',
                'goal' => ['kind' => 'gather', 'subject' => null, 'target' => 20],
                'gold' => 14,
            ],
            'full_day' => [
                'name' => "A Full Day's Work",
                'description' => 'Forty-five units carried out. That is four or five hexes worked through, and a bag that will need somewhere to go.',
                'goal' => ['kind' => 'gather', 'subject' => null, 'target' => 45],
                'gold' => 28,
            ],
            'deep_haul' => [
                'name' => 'Down to the Seam',
                'description' => 'Eighty units. A day at this asks the bag the question twice, and selling is only ever the worst of the answers.',
                'goal' => ['kind' => 'gather', 'subject' => null, 'target' => 80],
                'gold' => 45,
            ],
        ],

        // --------------------------------------------------------------- bench
        //
        // A settlement, either line of work it runs. Never a named line: a
        // village runs one of the five (§6), and which one is not the player's
        // choice.
        'bench' => [
            'off_the_line' => [
                'name' => 'Off the Line',
                'description' => 'Eight refined units off any settlement bench. Stay while it runs and it goes faster, and the line learns from your standing there.',
                'goal' => ['kind' => 'process', 'subject' => null, 'target' => 8],
                'gold' => 18,
            ],
            'long_run' => [
                'name' => 'The Long Run',
                'description' => 'Twenty-five refined units. A capital runs all five lines at once, which is most of what a capital is for.',
                'goal' => ['kind' => 'process', 'subject' => null, 'target' => 25],
                'gold' => 42,
            ],
            'something_made' => [
                'name' => 'Something Made',
                'description' => 'Make one thing at a bench — anything at all. Everything above the shop rung is made rather than bought.',
                'goal' => ['kind' => 'craft', 'subject' => null, 'target' => 1],
                'gold' => 25,
            ],
            'three_of_them' => [
                'name' => 'A Morning at the Anvil',
                'description' => 'Three things off the benches. One craft to a settlement, so this is a route rather than a queue.',
                'goal' => ['kind' => 'craft', 'subject' => null, 'target' => 3],
                'gold' => 48,
            ],
        ],

        // ---------------------------------------------------------------- road
        //
        // The walk, and the counter you walk to.
        'road' => [
            'stretch_the_legs' => [
                'name' => 'Stretch the Legs',
                'description' => 'Twenty hexes. Nothing else in the game pays for walking, which is the whole reason the Explorer exists.',
                'goal' => ['kind' => 'travel', 'subject' => null, 'target' => 20],
                'gold' => 15,
            ],
            'across_country' => [
                'name' => 'Across Country',
                'description' => 'Sixty hexes crossed. Five hours of road, and every hex of it widens the eye and deepens the pack.',
                'goal' => ['kind' => 'travel', 'subject' => null, 'target' => 60],
                'gold' => 40,
            ],
            'at_the_counter' => [
                'name' => 'At the Counter',
                'description' => 'Take sixty gold off the traders. The rate is bad on purpose — it is the option that is always open, never the good one.',
                'goal' => ['kind' => 'sell', 'subject' => null, 'target' => 60],
                'gold' => 16,
            ],
            'market_day' => [
                'name' => 'Market Day',
                'description' => 'A hundred and eighty gold across the counters. Worn gear sells too, at half its price and what is left of it.',
                'goal' => ['kind' => 'sell', 'subject' => null, 'target' => 180],
                'gold' => 34,
            ],
        ],
    ];

    /**
     * Which day it is, on the server's clock.
     *
     * Through `Balance::scaled()` like every other duration, so a fast clock
     * rolls the day over quickly and dailies are testable by hand. The boundary
     * is global rather than per-character: everybody's day turns at the same
     * instant, which is one less thing for a client to disagree about and
     * nothing anybody can shop around by changing a timezone.
     */
    public static function dayIndex(int $nowMs): int
    {
        return intdiv(max(0, $nowMs), self::dayLengthMs());
    }

    /** How long a day is in this environment. */
    public static function dayLengthMs(): int
    {
        return Balance::scaled(self::DAY_MS);
    }

    /** When the day this timestamp falls in ends, and the three roll over. */
    public static function dayEndsAt(int $nowMs): int
    {
        return (self::dayIndex($nowMs) + 1) * self::dayLengthMs();
    }

    /**
     * The three tasks for one character on one day, keyed by lane.
     *
     * Derived, so a day nobody played costs nothing to have existed. Per
     * character rather than per server: what today asks of you is your day,
     * and two prospectors standing on the same hex are not owed the same errand.
     *
     * @return array<string,string> lane => task key
     */
    public static function forDay(int $characterId, int $day): array
    {
        $out = [];

        foreach (self::LANES as $i => $lane) {
            $keys = array_keys(self::DEFS[$lane]);
            // The lane index is folded into the seed rather than the coordinate
            // so that adding a fourth lane never re-rolls the first three.
            $h = Hash::hash2($characterId, $day, Balance::mapSeed() ^ (0x0DA1 + $i * 0x9E37));
            $out[$lane] = $keys[Hash::randInt($h, 0, count($keys) - 1)];
        }

        return $out;
    }

    /**
     * A task by key, whichever lane it is in.
     *
     * Keys are unique across the whole pool -- there is a test pinning it --
     * because a claim names a task and nothing else, exactly as a quest claim
     * names a quest.
     *
     * @return array<string,mixed>|null
     */
    public static function task(string $key): ?array
    {
        foreach (self::DEFS as $lane => $tasks) {
            if (isset($tasks[$key])) {
                return $tasks[$key] + ['lane' => $lane];
            }
        }

        return null;
    }

    /**
     * Every task, flattened, each carrying the lane it came from.
     *
     * This is what the client is handed: the pool is static and identical for
     * everyone, so it ships once beside the quest catalog, and the state says
     * only which three are today's.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        $out = [];

        foreach (self::DEFS as $lane => $tasks) {
            foreach ($tasks as $key => $def) {
                $out[$key] = $def + ['lane' => $lane];
            }
        }

        return $out;
    }
}
