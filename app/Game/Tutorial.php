<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Onboarding script, §12. The step *copy* lives on the client; the server only
 * owns the cursor, because advancing it is a state change like any other and the
 * client must not be able to assert it.
 *
 * Each step waits for one real gameplay event -- there is nothing scripted to
 * unlearn, and a player who already knows the game completes it by playing.
 */
final class Tutorial
{
    /** Ordered list of the event each step is waiting for. */
    public const STEPS = [
        'mine_start',      //  0  work a forest hex
        'collect',         //  1  collect the wood
        'travel',          //  2  walk to the nearest settlement
        'sell',            //  3  sell it to the trader
        'buy',             //  4  buy a Stone Axe
        'equip',           //  5  equip the axe
        'collect',         //  6  cut wood with the axe
        'process_start',   //  7  process wood into planks
        'process_collect', //  8  collect your planks
        'craft',           //  9  craft a Wood Pickaxe
        'collect',         // 10  put the pickaxe to work
    ];

    /** Cursor value meaning "finished". */
    public const DONE = -1;

    /**
     * Advance the cursor if this event is the one the current step waits for.
     * Out-of-order play simply does not advance it, which is intended: the card
     * keeps asking for the same real action until the player does it.
     */
    public static function advance(int $step, string $event): int
    {
        if ($step < 0 || $step >= count(self::STEPS)) {
            return self::DONE;
        }

        if (self::STEPS[$step] !== $event) {
            return $step;
        }

        return $step + 1 >= count(self::STEPS) ? self::DONE : $step + 1;
    }
}
