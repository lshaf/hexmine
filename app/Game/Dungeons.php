<?php

declare(strict_types=1);

namespace App\Game;

/**
 * §9.6 -- what is standing on a dungeon floor, and where.
 *
 * A dungeon is the one place in the game that is not the map. Everything else is
 * a pure function of `(col, row, seed)` and is there whether anybody walks to it
 * or not; a floor is a pure function of `(col, row, floorSeed)` where the seed
 * belongs to a SESSION -- twelve hours long, one to six people, and gone.
 *
 * **The secret is the whole of what keeps the fog a fog.** §9.6.2 seeds a floor
 * from the session code, when it was opened, the floor number and whoever walked
 * in first, and every one of those four is something the player is holding. A
 * seed made of them alone is a seed the client can compute, and a client that can
 * compute the seed knows where every monster is standing. So a session carries
 * `seed_secret` -- 128 bits of CSPRNG -- and it is folded in here and handed to
 * nobody.
 *
 * The fold is SHA-256 rather than crc32, and that is the point of it: an
 * attacker who brute-forces a 32-bit floor seed learns the floor they are
 * already standing on and nothing else, because the secret is not recoverable
 * from it. With a cheap mix, one solved floor would hand over every other floor
 * in the session.
 *
 * **Derived per hex, never materialised.** There is no floor object, no cached
 * array of twenty-five hundred tiles and no repair pass: asking what stands on a
 * hex is one hash, exactly as it is out in the world. That is what lets the
 * live-state query answer a disc at a time (§9.6.2's second half -- the secret
 * stops a client DERIVING the layout, and the sight bound stops it being TOLD).
 */
final class Dungeons
{
    /** §9.6.3 -- which slots the top-tier table may roll. */
    public const CATEGORIES = ['tools', 'armor', 'weapons'];

    /** §9.6.3 -- hard is the same dungeon with the top two rungs doubled. */
    public const DIFFICULTIES = ['easy', 'hard'];

    /**
     * Salts, so one floor seed answers several independent questions.
     *
     * A floor asks where the entrance is, where the stair is and what stands on
     * each hex, and all three read the same seed. Without a salt apiece the
     * entrance roll and the monster roll for the hex at (0,0) are the same
     * number, which is the kind of correlation that does not fail -- it just
     * quietly puts the stair under a monster more often than chance.
     */
    private const SALT_ENTRANCE = 0x5E17;

    private const SALT_STAIR = 0x57A1;

    private const SALT_MONSTER = 0x3084;

    private const SALT_PICK = 0x91C4;

    private const SALT_SEEDED = 0x6C0A;

    /**
     * §9.6.2 -- the five guardians, one per dungeon, above the roster's tier four.
     *
     * They live here rather than in `Monsters::ROSTER` because a guardian belongs
     * to a dungeon rather than to a country -- and because that file is generated
     * by `gen_monsters.py`, which knows about rings and biomes and nothing about
     * floors.
     *
     * The pair is quoted at `SOLID_SCALE` like everything else solid (§7.3), and
     * these are the FLOOR-TEN figures: `guardian()` scales them down for the
     * floors above, so the tenth is the one the numbers were written for.
     *
     * **Every figure here is measured, not chosen.** §9.6.4's anchor is a single
     * sentence -- solo, floor ten, best-in-slot, a coin flip -- so the hp is
     * binary-searched against exactly that fighter (a legendary kit, job 30, a
     * maxed pair tree) until it lands between 43% and 57%.
     *
     * They were eyeballed first, as "above tier four", and every one of them was
     * unwinnable by ANY kit at ANY floor. Damage is `attack - defense`, so a
     * guardian whose guard clears the best attack in the game collapses every
     * round to the 1% chip, and 60 rounds of chip cannot clear five figures of
     * hp. A monster tuned by eye against the tier below it does not come out
     * hard; it comes out impossible, and it looks identical in a unit test that
     * only checks the ladder climbs.
     *
     * The second pass is why the attacks are as high as they are. With a gentler
     * bite the player survived to the bell and every loss was a DPS check --
     * §9.5.5 says failing to put something down is being driven off, which is
     * right for a wall on a road and wrong for the thing on the stair. At these
     * figures no floor-ten fight in two hundred reaches round sixty: the pool
     * decides it, which is what makes it a race.
     */
    public const GUARDIANS = [
        'rootvault' => ['name' => 'The Taproot', 'profile' => 'carapace', 'attack' => 8900, 'defense' => 2500, 'hp' => 66000, 'wearBias' => 1.0, 'gold' => [180, 300], 'description' => 'It was the floor until it stood up, and the floor has not grown back.'],
        'deepshaft' => ['name' => 'Shaft Nine', 'profile' => 'brute', 'attack' => 9600, 'defense' => 2050, 'hp' => 64000, 'wearBias' => 1.0, 'gold' => [180, 300], 'description' => 'Somebody sank a working here and something came up it.'],
        'beastwarren' => ['name' => 'The Whelping Sire', 'profile' => 'swift', 'attack' => 8600, 'defense' => 2300, 'hp' => 81500, 'wearBias' => 1.6, 'gold' => [180, 300], 'description' => 'Everything else on the ten floors above is its. It has been waiting to be told.'],
        'ashpit' => ['name' => 'Cinderthrone', 'profile' => 'brute', 'attack' => 9900, 'defense' => 2050, 'hp' => 59000, 'wearBias' => 1.0, 'gold' => [180, 300], 'description' => 'Still burning after whatever it was that put it out here.'],
        'windhollow' => ['name' => 'The Long Draught', 'profile' => 'swift', 'attack' => 8700, 'defense' => 2350, 'hp' => 76000, 'wearBias' => 1.5, 'gold' => [180, 300], 'description' => 'You hear the floor empty of air before you see what took it.'],
    ];

    // ------------------------------------------------------------- the seed

    /**
     * §9.6.2 -- fold the session, the floor and the secret into one 32-bit seed.
     *
     * Four of the five ingredients are the player's own and are here to make one
     * session's floors unlike another's; the fifth is the only one that makes the
     * result unguessable.
     */
    public static function floorSeed(
        string $secret,
        string $code,
        int $createdAt,
        int $firstPlayerId,
        int $floor,
    ): int {
        $material = $secret.'|'.$code.'|'.$createdAt.'|'.$firstPlayerId.'|'.$floor;

        return (int) hexdec(substr(hash('sha256', $material), 0, 8));
    }

    /** A fresh secret. Generated, never derived -- a hash of public things is not one. */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(Balance::DUNGEON_SECRET_BYTES));
    }

    // ------------------------------------------------------------ the floor

    /** Is this inside the fifty-by-fifty field at all? */
    public static function inBounds(int $col, int $row): bool
    {
        $n = Balance::DUNGEON_FLOOR_SIZE;

        return $col >= 0 && $col < $n && $row >= 0 && $row < $n;
    }

    /** §9.6.2 -- where you land. Rolled, so no two floors open in the same corner. */
    public static function entrance(int $seed): array
    {
        if (isset(self::$ends['e'.$seed])) {
            return self::$ends['e'.$seed];
        }

        $n = Balance::DUNGEON_FLOOR_SIZE;

        return self::$ends['e'.$seed] = [
            Hash::randInt(Hash::hash2(self::SALT_ENTRANCE, 1, $seed), 0, $n - 1),
            Hash::randInt(Hash::hash2(self::SALT_ENTRANCE, 2, $seed), 0, $n - 1),
        ];
    }

    /**
     * §9.6.2 -- where the floor lets out, and the guardian stands on it.
     *
     * Rolled from the same seed and then pushed away from the entrance until the
     * walk is worth calling one. The retry is deterministic (the attempt number
     * is part of the hash) so the same seed is the same stair, and it falls back
     * to the farthest corner rather than looping -- a generator that can spin is
     * worse than one that is occasionally predictable.
     */
    public static function stair(int $seed): array
    {
        if (isset(self::$ends['s'.$seed])) {
            return self::$ends['s'.$seed];
        }

        $n = Balance::DUNGEON_FLOOR_SIZE;
        [$ec, $er] = self::entrance($seed);
        $want = (int) floor($n * 0.6);

        for ($try = 0; $try < 24; $try++) {
            $col = Hash::randInt(Hash::hash2(self::SALT_STAIR, $try * 2, $seed), 0, $n - 1);
            $row = Hash::randInt(Hash::hash2(self::SALT_STAIR, $try * 2 + 1, $seed), 0, $n - 1);

            if (HexGeometry::distance($ec, $er, $col, $row) >= $want) {
                return self::$ends['s'.$seed] = [$col, $row];
            }
        }

        return self::$ends['s'.$seed] = [$ec < $n / 2 ? $n - 1 : 0, $er < $n / 2 ? $n - 1 : 0];
    }

    /**
     * §9.6.2 -- the six that are always there, within a short walk of the landing.
     *
     * **The kill gate cannot be left to density, and measuring it is what proves
     * that.** At 12% over a radius-8 disc a floor holds seven monsters on
     * average and the average is not the promise: swept over two thousand seeds,
     * one in three hundred comes up short of six, and at radius four one seed in
     * three does. A floor that strands somebody one time in three hundred is a
     * floor that strands somebody, and the failure is the worst kind -- it looks
     * like the gate is broken rather than like the roll was thin.
     *
     * So six hexes are seeded outright. They are picked by INDEX rather than by
     * rolling each hex, which is what keeps the whole thing derivable: answering
     * "is a monster here" stays one hash plus a six-entry membership test, with
     * no floor object, no cache and no scan.
     *
     * The band is a short walk rather than the doorstep -- §9.6.2 wants six met
     * on the way, not a welcoming committee on the landing.
     */
    private const SEEDED_NEAR = 3;

    private const SEEDED_FAR = 9;

    /**
     * Memoised per seed, because `monsterAt()` asks on every hex it is handed.
     *
     * A sight disc is thirty-seven tiles and each was recomputing six placements
     * with up to thirty-two deterministic retries apiece -- about two hundred
     * hashes per hex, to answer a question with one answer per floor. Keyed by
     * the floor seed, so a party of six on one floor shares one entry and a
     * long-lived process holds one per floor anybody is standing on.
     */
    private static array $seeded = [];

    /** Same argument for the two fixed points: one answer per floor, asked per hex. */
    private static array $ends = [];

    /** The six seeded hexes for this floor, distinct and never the landing itself. */
    public static function seededHexes(int $seed): array
    {
        if (isset(self::$seeded[$seed])) {
            return self::$seeded[$seed];
        }

        $n = Balance::DUNGEON_FLOOR_SIZE;
        [$ec, $er] = self::entrance($seed);
        $out = [];

        for ($i = 0; $i < Balance::DUNGEON_FLOOR_KILLS; $i++) {
            // Deterministic retry: the attempt is part of the hash, so a
            // collision resolves the same way on every machine that asks.
            for ($try = 0; $try < 32; $try++) {
                $h = Hash::hash2(self::SALT_SEEDED + $i, $try, $seed);
                $dist = Hash::randInt($h, self::SEEDED_NEAR, self::SEEDED_FAR);
                $dir = Hash::randInt(Hash::hash2(self::SALT_SEEDED + $i, $try + 64, $seed), 0, 5);

                [$col, $row] = self::step($ec, $er, $dir, $dist);

                if (! self::inBounds($col, $row)) {
                    continue;
                }

                if ($col === $ec && $row === $er) {
                    continue;
                }

                if (in_array([$col, $row], $out, true)) {
                    continue;
                }

                $out[] = [$col, $row];
                break;
            }
        }

        return self::$seeded[$seed] = $out;
    }

    /** Drop the memo. Tests lean on this the way they lean on `WorldGen::forget()`. */
    public static function forget(): void
    {
        self::$seeded = [];
        self::$ends = [];
    }

    private static function isSeeded(int $seed, int $col, int $row): bool
    {
        return in_array([$col, $row], self::seededHexes($seed), true);
    }

    /** Walk `dist` hexes along one of the six cube directions, in offset coords. */
    private static function step(int $col, int $row, int $dir, int $dist): array
    {
        // Flat-top cube directions, matching HexGeometry's axes.
        $dirs = [[1, -1, 0], [1, 0, -1], [0, 1, -1], [-1, 1, 0], [-1, 0, 1], [0, -1, 1]];
        [$dx, $dy, $dz] = $dirs[$dir % 6];

        $x = $col;
        $z = $row - (int) (($col - ($col & 1)) / 2);
        $y = -$x - $z;

        $x += $dx * $dist;
        $y += $dy * $dist;
        $z += $dz * $dist;

        return [$x, $z + (int) (($x - ($x & 1)) / 2)];
    }

    /**
     * §9.6.2 -- what stands on this hex, or null for open floor.
     *
     * One hash, no state. The entrance is always clear (landing inside a fight
     * is not a decision anybody made) and the stair carries the guardian rather
     * than an ordinary monster, so both are excluded here and answered by their
     * own callers.
     */
    public static function monsterAt(int $seed, string $dungeon, string $difficulty, int $floor, int $col, int $row): ?string
    {
        if (! self::inBounds($col, $row)) {
            return null;
        }

        if ([$col, $row] === self::entrance($seed) || [$col, $row] === self::stair($seed)) {
            return null;
        }

        $roll = Hash::rand01(Hash::hash2($col ^ self::SALT_MONSTER, $row, $seed));
        if ($roll >= Balance::DUNGEON_MONSTER_DENSITY && ! self::isSeeded($seed, $col, $row)) {
            return null;
        }

        $pool = self::poolFor($dungeon, self::tierFor($floor, $difficulty));
        if ($pool === []) {
            return null;
        }

        $pick = Hash::randInt(Hash::hash2($col ^ self::SALT_PICK, $row, $seed), 0, count($pool) - 1);

        return $pool[$pick];
    }

    /**
     * §9.6.2 -- how hard the ordinary monsters are, by depth.
     *
     * Hard shifts the whole ladder up one and floor ten tops out either way, so
     * the difference between the two contracts at the bottom of a dungeon is the
     * drop table (§9.6.8) rather than the roster -- there is no tier five to
     * promote a hard floor ten into, and the guardian is the tier above anyway.
     */
    public static function tierFor(int $floor, string $difficulty): int
    {
        $tier = (int) ceil(max(1, min(Balance::DUNGEON_FLOORS, $floor)) / 3);

        if ($difficulty === 'hard') {
            $tier++;
        }

        return max(1, min(4, $tier));
    }

    /**
     * §9.6.2 -- which creatures a dungeon fields at a tier.
     *
     * Four of the five are a country's and field that country's five. **Beastwarren
     * belongs to no country**, so it pools all four.
     *
     * §9.6.2 said it should field the hunt's own roster instead, and that is
     * wrong for a reason worth keeping: §5.5 makes a hunt a MINE rather than a
     * fight, and the eight animals carry no attack, defense or hp at all. Giving
     * them a pair to make them eligible here would turn the hunting line's
     * quarry into monsters, which is the one thing §5.5 spent a whole section
     * undoing. What dens in the beast dungeon is everything.
     */
    public static function poolFor(string $dungeon, int $tier): array
    {
        $biome = null;
        foreach (Catalog::DUNGEONS as $site) {
            if ($site['key'] === $dungeon) {
                $biome = $site['biome'];
                break;
            }
        }

        $keys = [];
        foreach (Monsters::ROSTER as $key => $monster) {
            if ((int) $monster['tier'] !== $tier) {
                continue;
            }

            if ($biome !== null && $monster['biome'] !== $biome) {
                continue;
            }

            $keys[] = $key;
        }

        sort($keys);

        return $keys;
    }

    // --------------------------------------------------------- the guardian

    /**
     * §9.6.2 -- the thing on the stair, scaled by depth and by the roster.
     *
     * **Pool scales with the party and attack does not**, which is §9.6.4's own
     * rule arriving where it is actually spent: the guardian answers once per
     * living member rather than hitting N times as hard, so a six-party kills it
     * in the same rounds a solo does and pays the same bill per head. Scaling the
     * attack as well would charge the party twice for being one.
     *
     * The floor ramp bottoms out at the low end rather than at zero, because a
     * floor-one guardian is still a guardian -- it is the sixth kill of the
     * floor (§9.6.2) and the thing the stair waits on.
     */
    public static function guardian(string $dungeon, string $difficulty, int $floor, int $party = 1): array
    {
        $base = self::GUARDIANS[$dungeon] ?? null;
        if ($base === null) {
            throw new GameException('Unknown dungeon.');
        }

        $floor = max(1, min(Balance::DUNGEON_FLOORS, $floor));
        $ramp = 0.35 + 0.065 * $floor;

        if ($difficulty === 'hard') {
            $ramp *= 1.25;
        }

        $party = max(1, min(Balance::DUNGEON_PARTY_MAX, $party));

        return [
            'key' => 'guardian_'.$dungeon,
            'name' => $base['name'],
            'guardian' => true,
            'dungeon' => $dungeon,
            'floor' => $floor,
            'profile' => $base['profile'],
            'tier' => 5,
            'attack' => (int) round($base['attack'] * $ramp),
            'defense' => (int) round($base['defense'] * $ramp),
            'hp' => (int) round($base['hp'] * $ramp) * $party,
            'wearBias' => $base['wearBias'],
            'gold' => [
                (int) round($base['gold'][0] * $ramp),
                (int) round($base['gold'][1] * $ramp),
            ],
            'description' => $base['description'],
        ];
    }

    // ------------------------------------------------------------- the gate

    /**
     * §9.6.2 -- has this floor been paid for?
     *
     * Six of its monsters, summed across the roster, guardian included. Never
     * per member: six is what the FLOOR costs.
     */
    public static function floorOpen(int $kills): bool
    {
        return $kills >= Balance::DUNGEON_FLOOR_KILLS;
    }

    /**
     * §9.6.2 -- the guardian will not rouse until five have fallen.
     *
     * Left to a bare counter a party could walk past everything, put the
     * guardian down with a tally of one, and find the stair still shut: the
     * climax of the floor followed by five errands. Roused instead, the
     * requirement names itself where a player would bump into it.
     */
    public static function guardianRoused(int $kills): bool
    {
        return $kills >= Balance::DUNGEON_FLOOR_KILLS - 1;
    }
}
