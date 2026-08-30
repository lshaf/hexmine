<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Quests, §12 -- the gold faucet §3.2 promised, and the only thing in the game
 * that tells a prospector what to do next.
 *
 * **This replaced the tutorial rather than sitting beside it.** The old §12 was
 * eleven scripted steps and a card in the corner, and it was always the real
 * game loop -- there was nothing to unlearn. What it never had was a reason to
 * finish: it paid nothing, and a prompt that pays nothing is a prompt to
 * dismiss. The same lessons in the same order, each with gold on the end of it,
 * is the same teaching with a stake in it. The first eight quests below ARE the
 * old script, and there is exactly one place a player looks to find out what to
 * do next instead of two.
 *
 * Three rules shape all of it.
 *
 * **One-shot, per character, forever.** A quest is claimed once and never comes
 * back. That is the cap that makes it safe for §2: an unbounded gold faucet is
 * a bot's whole business plan, and a finite list of one-time payouts is worth
 * exactly as much to a thousand wallets as it is to one -- which is to say, not
 * enough to be worth farming. Dailies landed with their own cap and their own
 * argument -- see Dailies (§12.2). They are a separate ledger sharing this
 * one's counters, never a row in DEFS.
 *
 * **Gold and only gold.** §3.3 forbids a grind→NFT faucet outright, and §3.2
 * makes gold the currency that may be inflated because it never bridges to
 * anything external. A quest that paid a rare material would be a hole in the
 * threat model rather than a nicer reward.
 *
 * **Nothing here is a new verb.** Every goal counts something the player was
 * going to do anyway -- a haul, a walk, a run at a bench, a sale. A quest that
 * asked for an action existing only to satisfy quests would be a second game
 * played beside this one.
 *
 * Adding more is meant to be dull: a row in DEFS, and the counter it rides on
 * already exists. That is the whole point of the `goal.kind` set being small.
 */
final class Quests
{
    /**
     * A goal that accumulates from play. The counter is a stored integer on the
     * character's row for that quest, bumped by GameService as work is done.
     */
    public const COUNTED = ['gather', 'process', 'craft', 'buy', 'equip', 'travel', 'sell'];

    /**
     * A goal that is a fact about the character rather than a tally. Read fresh
     * every time it is asked about and never stored -- a levelled character who
     * lost the row would otherwise have to re-earn a level they already have.
     */
    public const MEASURED = ['level', 'job'];

    /**
     * The quests, in the order they unlock.
     *
     * `goal.subject` narrows a counter: a material key for `gather`, a
     * processing line for `process`, a craft category for `craft`, a job key for
     * `job`. Null means "any", which is what makes the early ones reachable
     * however a player chooses to play.
     *
     * `requires` names the quest that must be **claimed** first, so the list is
     * a chain rather than a wall of twenty tasks on day one. A quest whose
     * prerequisite is unclaimed is not shown at all: what is next should be
     * legible, and what is after that is not yet the player's problem.
     *
     * @var array<string,array<string,mixed>>
     */
    public const DEFS = [
        // ---------------------------------------------------------- the arc §12
        //
        // What used to be the tutorial. It is the same nine lessons in the same
        // order, and it is the actual game loop rather than a scripted one --
        // there was never anything here to unlearn, which is why it converts to
        // quests without losing a thing.
        //
        // What it gains is a reason to finish: the tutorial paid nothing, and a
        // prompt in the corner that pays nothing is a prompt to dismiss. It also
        // gains a place to live. §5.4 guarantees a forest spawn with a
        // woodcutting village in reach, so this arc can name wood, a stone axe
        // and a saw pit without ever soft-locking anybody.
        'bare_hands' => [
            'name' => 'What the Ground Gives',
            'description' => 'Work the forest with nothing in your hands and bring back five branches. A hex is never closed to you; it just pays badly.',
            'goal' => ['kind' => 'gather', 'subject' => 'branch', 'target' => 5],
            'gold' => 10,
            'requires' => null,
        ],

        'first_coin' => [
            'name' => 'A Poor Rate, Honestly Given',
            'description' => 'Sell branches at a village until you have taken five gold. The rate is bad on purpose — that is the lesson, and the argument for a tool.',
            'goal' => ['kind' => 'sell', 'subject' => null, 'target' => 5],
            'gold' => 15,
            'requires' => 'bare_hands',
        ],

        'a_stone_axe' => [
            'name' => 'Gold Well Spent',
            'description' => 'Buy a Stone Axe from a village trader. Gold buys the bottom two rungs of the ladder and never the top.',
            'goal' => ['kind' => 'buy', 'subject' => 'stone_axe', 'target' => 1],
            'gold' => 20,
            'requires' => 'first_coin',
        ],

        'on_the_belt' => [
            'name' => 'On the Belt',
            'description' => 'Put the axe on. A tool pays out on its own line and nowhere else — an axe is for the forest, and it does nothing to a seam.',
            'goal' => ['kind' => 'equip', 'subject' => 'axe', 'target' => 1],
            'gold' => 20,
            'requires' => 'a_stone_axe',
        ],

        'the_real_thing' => [
            'name' => 'The Real Thing',
            'description' => 'Work the same forest again, now that you are holding something. Ten wood, and not a branch among them.',
            'goal' => ['kind' => 'gather', 'subject' => 'wood', 'target' => 10],
            'gold' => 30,
            'requires' => 'on_the_belt',
        ],

        'saw_it_down' => [
            'name' => 'Raw Into Made',
            'description' => 'Take two planks off a saw pit. Stay while it runs and the work goes faster, and the line learns from your standing there.',
            'goal' => ['kind' => 'process', 'subject' => 'woodcutting', 'target' => 2],
            'gold' => 35,
            'requires' => 'the_real_thing',
        ],

        'hewn_axe' => [
            'name' => 'Made, Not Bought',
            'description' => 'Forge a Hewn Axe out of your own planks. Everything above the shop rungs is made, never bought.',
            'goal' => ['kind' => 'craft', 'subject' => 'hewn_axe', 'target' => 1],
            'gold' => 45,
            'requires' => 'saw_it_down',
        ],

        'back_to_the_trees' => [
            'name' => 'Back to the Trees',
            'description' => 'Twenty-five wood with the axe you made. The loop closes here, and every loop after it is this one with bigger numbers.',
            'goal' => ['kind' => 'gather', 'subject' => 'wood', 'target' => 25],
            'gold' => 55,
            'requires' => 'hewn_axe',
        ],

        // ------------------------------------------------------- and outward
        //
        // Where the old tutorial ended on a sentence about the contested ring,
        // the ledger just keeps going. Each of these points at one system the
        // arc above only touched: the road, the trader, the benches, the seams.
        'short_road' => [
            'name' => 'Out of Sight of the Village',
            'description' => 'Walk twenty-five hexes. There is no reach limit and never will be — distance costs hours, which is the only currency an idle game cannot inflate.',
            'goal' => ['kind' => 'travel', 'subject' => null, 'target' => 25],
            'gold' => 60,
            'requires' => 'back_to_the_trees',
        ],

        'traders_rate' => [
            'name' => "The Trader's Rate",
            'description' => 'Take two hundred gold off the traders. Selling is the worst of your options and always available, which is what makes the other two decisions.',
            'goal' => ['kind' => 'sell', 'subject' => null, 'target' => 200],
            'gold' => 80,
            'requires' => 'back_to_the_trees',
        ],

        'first_refine' => [
            'name' => 'Ten Off the Bench',
            'description' => 'Take ten refined units off settlement benches. A village runs one line of the five, a city two, a capital all of them.',
            'goal' => ['kind' => 'process', 'subject' => null, 'target' => 10],
            'gold' => 90,
            'requires' => 'back_to_the_trees',
        ],

        'deep_seam' => [
            'name' => 'The Mountain Owes You',
            'description' => 'Carry thirty iron ore out of the mountains. A second line means a second tool, and the skill points to justify it.',
            'goal' => ['kind' => 'gather', 'subject' => 'iron_ore', 'target' => 30],
            'gold' => 120,
            'requires' => 'first_refine',
        ],

        'journeyman' => [
            'name' => 'Journeyman',
            'description' => 'Reach character level five. Levels buy capacity — where you may go — and never power.',
            'goal' => ['kind' => 'level', 'subject' => null, 'target' => 5],
            'gold' => 150,
            'requires' => 'traders_rate',
        ],

        'sawyers_hand' => [
            'name' => 'A Hand at the Pit',
            'description' => 'Reach Sawyer level five. A bench rewards the one who keeps coming back to it, and only that one.',
            'goal' => ['kind' => 'job', 'subject' => 'sawyer', 'target' => 5],
            'gold' => 180,
            'requires' => 'first_refine',
        ],

        'long_walk' => [
            'name' => 'The Long Walk',
            'description' => 'Cross two hundred and fifty hexes. Nothing pays for a road this long except the road itself — which is exactly what the Explorer is for.',
            'goal' => ['kind' => 'travel', 'subject' => null, 'target' => 250],
            'gold' => 250,
            'requires' => 'short_road',
        ],

        'toward_the_ring' => [
            'name' => 'Toward the Contested Ring',
            'description' => 'Reach character level twelve. The rarer ground is inward, the best benches stand on it, and it is not quiet there.',
            'goal' => ['kind' => 'level', 'subject' => null, 'target' => 12],
            'gold' => 450,
            'requires' => 'journeyman',
        ],
    ];

    /** @return array<string,mixed>|null */
    public static function def(string $key): ?array
    {
        return self::DEFS[$key] ?? null;
    }

    /** True for a goal that accumulates rather than being read off the character. */
    public static function isCounted(string $key): bool
    {
        $def = self::def($key);

        return $def !== null && in_array($def['goal']['kind'], self::COUNTED, true);
    }

    /**
     * Quests whose goal rides a given counter and whose subject matches.
     *
     * A null subject on the quest means "any", so it always matches; a named one
     * has to be the same string. That single rule is what lets "bring back ten
     * of anything" and "bring back thirty iron ore" be the same mechanism.
     *
     * A caller may fire the same work under more than one subject -- a craft
     * goes up as its item key AND as its bench category, an equip as the item
     * and as the slot -- which is what lets a quest name whichever of the two it
     * actually means without the matcher knowing anything about either.
     *
     * @return array<int,string>
     */
    public static function counting(string $kind, ?string $subject): array
    {
        $out = [];

        foreach (self::DEFS as $key => $def) {
            $goal = $def['goal'];
            if ($goal['kind'] !== $kind) {
                continue;
            }
            if ($goal['subject'] !== null && $goal['subject'] !== $subject) {
                continue;
            }

            $out[] = $key;
        }

        return $out;
    }
}
