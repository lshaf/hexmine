<?php

declare(strict_types=1);

namespace App\Game;

/**
 * §7 -- what an unnamed character is called.
 *
 * Every character used to read "Prospector" until it claimed a name, which made
 * a map of strangers a map of one stranger repeated. This is a name derived
 * from the row id instead: stable, unique by construction, and no more claimed
 * than the label it replaces.
 *
 * The naming is still owed. `name` stays null until it is spent (§7), so this
 * is a LABEL and nothing else -- it never enters the unique index and it cannot
 * collide with a claimed name, because a claimed one has to pass the same
 * refusal the reserved word always did.
 */
final class Names
{
    /** Deliberately plain: these read as a person, not as a title to live up to. */
    private const FIRST = [
        'Ash', 'Bram', 'Cole', 'Dell', 'Ember', 'Finn', 'Gale', 'Hollis',
        'Iver', 'Juno', 'Kest', 'Lark', 'Mor', 'Nell', 'Orin', 'Pike',
        'Quill', 'Rook', 'Sable', 'Tor', 'Vane', 'Wren', 'Yarrow', 'Zeph',
    ];

    private const SECOND = [
        'foot', 'hand', 'wood', 'stone', 'brook', 'ridge', 'fell', 'moor',
        'thorn', 'vale', 'crag', 'mere', 'hollow', 'barrow', 'reach', 'span',
    ];

    /**
     * The label for a character that has not claimed a name.
     *
     * Derived rather than rolled, so it is the same every time it is read and
     * nothing has to be stored. The id is folded through the map seed so two
     * servers do not hand out the same list in the same order.
     */
    public static function forCharacter(int $id): string
    {
        $h = Hash::hash2($id, 0x4E1D, Balance::mapSeed() ^ 0x4E41);

        $first = self::FIRST[Hash::randInt($h, 0, count(self::FIRST) - 1)];
        $second = self::SECOND[Hash::randInt(
            Hash::hash2($id, 0x5E2E, Balance::mapSeed() ^ 0x4E42),
            0,
            count(self::SECOND) - 1,
        )];

        // The number is what makes it unique rather than merely varied: the
        // word pair gives 384 combinations and a server will outgrow that.
        return $first.$second.' '.(1000 + ($id % 9000));
    }
}
