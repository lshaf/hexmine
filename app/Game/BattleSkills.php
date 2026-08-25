<?php

declare(strict_types=1);

namespace App\Game;

/**
 * §9.5.9 -- what a weapon knows how to do, beyond swinging.
 *
 * Nine of them, three to a family, and no two families share a trick. That is
 * the whole design brief: §9.5.4 already makes the family in the slot your
 * class, and three copies of "hit harder" in three costumes would have made
 * that choice cosmetic. What a shieldbearer does in a fight should be
 * unavailable to a runecaster, and the reverse.
 *
 * Each family gets the answer to its own problem (§9.5.4):
 *
 *   Shield   kills slowly and pays the most, so its skills turn being hit into
 *            damage and buy rounds where nothing comes back.
 *   Sword    is the reference and remarkable at nothing, so its skills are
 *            about ATTRITION -- more swings, answered blows, and a guard that
 *            never comes back up.
 *   Focus    has no guard anywhere in the kit, so its skills are about ending
 *            the fight before that matters: piercing, burning, escalating.
 *
 * They come with the WEAPON, not with a skill point. §7.4.1 keeps job level as
 * the proof you have done the work rather than a reward for it, so carrying a
 * sword is what makes you a Swordhand and the three sword skills are simply
 * what a sword is. What a point buys is the tree that sharpens them
 * (§7.4.3: `skillPower`, `skillCooldown`, `skillStun`).
 *
 * EVERY SKILL STARTS A FIGHT ON COOLDOWN, and the cooldowns are long. An
 * opening alpha strike would decide fights §9.5.5 wants decided by the
 * exchange, and a rout is meant to be a rout: a pack put down in four rounds
 * sees no skills at all. They are what a LONG fight is for, which is exactly
 * where the shield needs them.
 */
final class BattleSkills
{
    /**
     * The effect fields a skill may carry. A skill sets one or two of them and
     * the exchange applies whichever are present, so a new skill is a row here
     * rather than a new branch in the loop.
     *
     * `power`   multiplier on this blow
     * `pierce`  this blow ignores the foe's guard entirely
     * `stun`    rounds the foe loses its answer for
     * `burn`    rounds of over-time, at `tick` x attack, ignoring guard
     * `strikes` rounds during which you swing twice
     * `riposte` rounds during which a blow that lands on you is answered
     * `sunder`  points off the foe's guard, PERMANENTLY, and it stacks
     * `stance`  rounds during which `share` of what gets through is stored
     *           rather than suffered, returned as one blow when it ends
     * `ramp`    added to the multiplier for every round already fought
     */
    public const SKILLS = [
        // -------------------------------------------------------------- shield
        //
        // A shieldbearer's problem is not surviving, it is finishing -- 14
        // attack against 31 defense at legendary (§9.5.4), and a slow kill is
        // more rounds of both wear streams. Every one of these converts the
        // thing it is good at into the thing it is not.
        'shield_bash' => [
            'family' => 'shield',
            'name' => 'Shield Bash',
            'glyph' => 'bash',
            'cooldown' => 11,
            'stun' => 2,
            'effect' => 'Slam the rim into your foe, stunning it.',
            'description' => 'They teach you early that the rim is a weapon. Most people find out later.',
        ],
        'anvil_stance' => [
            'family' => 'shield',
            'name' => 'Anvil Stance',
            'glyph' => 'anvil',
            'cooldown' => 14,
            'stance' => 3,
            'share' => 0.5,
            'effect' => 'Set behind your shield. Part of every blow is stored rather than suffered, then returned all at once.',
            'description' => 'Feet planted, shoulder set. Let it come.',
        ],
        'wardens_toll' => [
            'family' => 'shield',
            'name' => "Warden's Toll",
            'glyph' => 'toll',
            'cooldown' => 12,
            'toll' => true,
            'effect' => 'Swing with everything you are wearing behind it.',
            'description' => 'Everything the smith gave you, given back at once.',
        ],

        // --------------------------------------------------------------- sword
        //
        // The reference family, and its skills say so: nothing here is
        // spectacular and all of it compounds. A swordhand wins fights that
        // were already going to be long, by making every round after this one
        // worth slightly more than the last.
        'onslaught' => [
            'family' => 'sword',
            'name' => 'Onslaught',
            'glyph' => 'onslaught',
            'cooldown' => 10,
            'strikes' => 2,
            'effect' => 'Press the attack, striking twice each round.',
            'description' => 'Never give it a round to think in.',
        ],
        'sunder' => [
            'family' => 'sword',
            'name' => 'Sunder',
            'glyph' => 'sunder',
            'cooldown' => 12,
            'sunder' => 3,
            'effect' => "Cut into your foe's guard. It does not recover.",
            'description' => 'Armor was only ever a delay.',
        ],
        'riposte' => [
            'family' => 'sword',
            'name' => 'Riposte',
            'glyph' => 'riposte',
            'cooldown' => 13,
            'riposte' => 3,
            'effect' => 'Answer every blow the moment it lands.',
            'description' => 'The blade was already going back before you decided to send it.',
        ],

        // --------------------------------------------------------------- focus
        //
        // 27 attack and 7 guard at legendary (§9.5.4), and no defense anywhere
        // else in the kit -- a runecaster that has not finished the fight is
        // losing it. All three are about the clock.
        'ember_bolt' => [
            'family' => 'focus',
            'name' => 'Ember Bolt',
            'glyph' => 'ember',
            'cooldown' => 11,
            'pierce' => true,
            'burn' => 3,
            'tick' => 0.22,
            'effect' => 'Burn your foe through its guard, and leave it burning.',
            'description' => 'It goes in cold and it does not come out.',
        ],
        'chain_arc' => [
            'family' => 'focus',
            'name' => 'Chain Arc',
            'glyph' => 'arc',
            'cooldown' => 10,
            'power' => 1.2,
            'ramp' => 0.05,
            'effect' => 'Loose the charge you have been building all fight.',
            'description' => 'You have been holding this since the first round.',
        ],
        'rune_of_binding' => [
            'family' => 'focus',
            'name' => 'Rune of Binding',
            'glyph' => 'bind',
            'cooldown' => 15,
            'stun' => 1,
            'effect' => 'Bind your foe where it stands, stunning it.',
            'description' => 'One syllable, and it forgets what it was doing.',
        ],
    ];

    /**
     * The three a family carries, in the order they are OFFERED.
     *
     * Order is the tie-break: when more than one is off cooldown on the same
     * round, the first one listed goes. They desync on their own after that,
     * because using one resets only that one -- so a long fight rotates through
     * all three rather than repeating the cheapest.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function forFamily(?string $family): array
    {
        if ($family === null) {
            return [];
        }

        return array_filter(
            self::SKILLS,
            static fn (array $skill): bool => $skill['family'] === $family,
        );
    }

    /** @return array<string,mixed>|null */
    public static function get(string $key): ?array
    {
        return self::SKILLS[$key] ?? null;
    }

    /**
     * §9.5.9 -- a skill as the genre has taught players to read one.
     *
     * The shape is the one Guild Wars 2 uses and every MMO is a variation of:
     * a plain VERB-FIRST sentence saying what the skill does, then LABELLED
     * ROWS carrying every number. "Bash your foe with your shield and stun
     * them", then `Stun: 2 seconds`. Not one dense paragraph with figures
     * buried in it, which is what this used to be.
     *
     * The split is also what makes the prose safe to hand-write. **No number
     * ever appears in the sentence** -- every figure is derived here from the
     * very fields the exchange reads, off the ARMED skill, so a player who has
     * bought a node sees their own numbers and nothing can drift out of step
     * with the arithmetic. A typed "stuns for 2 rounds" would be wrong for
     * everybody who bought `skillStun`.
     *
     * Two rows are on every skill, because they are the two a player compares
     * across all three: what it costs in patience, and when it first arrives.
     * MMOs put recharge at the top for the same reason.
     *
     * @param  array<string,mixed>  $skill  an ARMED skill, so the tree is in it
     * @return array{effect:string,stats:list<array{label:string,value:string}>}
     */
    public static function summary(array $skill): array
    {
        $stats = [];

        if (isset($skill['stun'])) {
            $stats[] = ['label' => 'Stun', 'value' => self::rounds((int) $skill['stun'])];
        }

        if (isset($skill['power'])) {
            $stats[] = ['label' => 'Damage', 'value' => 'x'.self::figure((float) $skill['power'])];
        }

        if (isset($skill['ramp'])) {
            $stats[] = [
                'label' => 'Building',
                'value' => '+'.round($skill['ramp'] * 100).'% per round already fought',
            ];
        }

        if (! empty($skill['pierce'])) {
            $stats[] = ['label' => 'Guard', 'value' => 'ignored'];
        }

        if (isset($skill['burn'])) {
            $stats[] = [
                'label' => 'Burn',
                'value' => round($skill['tick'] * 100).'% of your attack a round, through guard',
            ];
            $stats[] = ['label' => 'Duration', 'value' => self::rounds((int) $skill['burn'])];
        }

        if (isset($skill['strikes'])) {
            $stats[] = ['label' => 'Strikes', 'value' => 'twice a round'];
            $stats[] = ['label' => 'Duration', 'value' => self::rounds((int) $skill['strikes'])];
        }

        if (isset($skill['riposte'])) {
            $stats[] = ['label' => 'Answers', 'value' => 'the whole blow, through guard'];
            $stats[] = ['label' => 'Duration', 'value' => self::rounds((int) $skill['riposte'])];
        }

        if (isset($skill['sunder'])) {
            $stats[] = ['label' => 'Guard', 'value' => '-'.$skill['sunder'].', permanent'];
            $stats[] = ['label' => 'Stacks', 'value' => 'every time it lands'];
        }

        if (isset($skill['stance'])) {
            $stats[] = ['label' => 'Stored', 'value' => round($skill['share'] * 100).'% of every blow'];
            $stats[] = ['label' => 'Duration', 'value' => self::rounds((int) $skill['stance'])];
            $stats[] = ['label' => 'Returned', 'value' => 'as one blow when it ends'];
        }

        if (! empty($skill['toll'])) {
            $stats[] = ['label' => 'Damage', 'value' => 'your attack plus your whole defense'];
        }

        $cooldown = (int) $skill['cooldown'];
        $stats[] = ['label' => 'Cooldown', 'value' => self::rounds($cooldown)];

        // The one row no MMO needs and this game does: nothing is armed at the
        // bell (§9.5.9), so the round it first becomes available is a fact a
        // player weighs before closing on something.
        $stats[] = ['label' => 'First use', 'value' => 'round '.$cooldown];

        return [
            'effect' => (string) ($skill['effect'] ?? $skill['description']),
            'stats' => $stats,
        ];
    }

    private static function rounds(int $n): string
    {
        return $n.' round'.($n === 1 ? '' : 's');
    }

    /** Trims a trailing zero, so 2.0 prints as 2 and 1.25 stays 1.25. */
    private static function figure(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * §9.5.9 -- one family's skills with the tree already folded in.
     *
     * Resolved here rather than inside the exchange so the preview, the fight
     * and the panel read one set of numbers. Every upgrade is clamped at its
     * own cap on the way in -- an exchange is a bad place to discover a cap was
     * missed somewhere upstream.
     *
     * @param  array{power?:float,cooldown?:int,stun?:int}  $tree
     * @return list<array<string,mixed>>
     */
    public static function armed(?string $family, array $tree = []): array
    {
        $power = max(0.0, min((float) ($tree['power'] ?? 0.0), Balance::SKILL_BATTLE_POWER_CAP));
        $shorter = max(0, min((int) ($tree['cooldown'] ?? 0), Balance::SKILL_BATTLE_COOLDOWN_CAP));
        $longer = max(0, min((int) ($tree['stun'] ?? 0), Balance::SKILL_BATTLE_STUN_CAP));

        $out = [];

        foreach (self::forFamily($family) as $key => $skill) {
            $armed = $skill + ['key' => $key];

            // Never under the floor, or a skill fires every round and the whole
            // point of a cooldown is gone (§9.5.9).
            $armed['cooldown'] = max(Balance::BATTLE_SKILL_MIN_COOLDOWN, $skill['cooldown'] - $shorter);

            // §7.4.3 -- the tree sharpens the EXTRA rather than the whole blow.
            // A node that moved a x1.2 arc to x1.5 would be worth more than a
            // rung of gear, which no tree may be.
            if (isset($skill['power'])) {
                $armed['power'] = 1 + ($skill['power'] - 1) * (1 + $power);
            }
            foreach (['tick', 'ramp', 'share'] as $scaled) {
                if (isset($skill[$scaled])) {
                    $armed[$scaled] = $skill[$scaled] * (1 + $power);
                }
            }
            if (isset($skill['sunder'])) {
                $armed['sunder'] = (int) ceil($skill['sunder'] * (1 + $power));
            }
            if (isset($skill['stun'])) {
                $armed['stun'] = $skill['stun'] + $longer;
            }

            $out[] = $armed;
        }

        return $out;
    }
}
