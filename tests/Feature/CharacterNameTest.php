<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\GameException;
use App\Game\GameService;
use App\Game\Names;
use App\Models\Character;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * §7 -- claiming a name.
 *
 * Two rules, and the second is the one with teeth: letters and digits only, and
 * no two prospectors hold the same name. A name is drawn beside other people's
 * on a shared map, so anything that lets one character be mistaken for another
 * is the thing being prevented.
 */
final class CharacterNameTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = app(GameService::class);
    }

    /**
     * §7 -- unnamed, and drawn with a derived name of its own rather than one
     * label every character in the game shares.
     */
    public function test_a_prospector_starts_unnamed_and_is_shown_a_derived_name(): void
    {
        $character = $this->character('wallet-one');

        $this->assertNull($character->name, 'the naming is still owed');

        $state = $this->game->playerState($character);
        $this->assertSame(Names::forCharacter((int) $character->id), $state['character']['name']);
        $this->assertFalse($state['character']['named']);
        $this->assertNotSame('Prospector', $state['character']['name']);
    }

    /** Derived from the row, so it is stable and no two characters share one. */
    public function test_the_derived_name_is_stable_and_unique(): void
    {
        $seen = [];

        for ($id = 1; $id <= 400; $id++) {
            $name = Names::forCharacter($id);
            $this->assertSame($name, Names::forCharacter($id), 'the name moved');
            $this->assertArrayNotHasKey($name, $seen, "two characters are called {$name}");
            $seen[$name] = true;
        }
    }

    public function test_a_name_is_claimed_and_then_shown(): void
    {
        $character = $this->game->renameCharacter($this->character('wallet-one'), 'Digger7');

        $this->assertSame('Digger7', $character->fresh()->name);

        $state = $this->game->playerState($character->fresh());
        $this->assertSame('Digger7', $state['character']['name']);
        $this->assertTrue($state['character']['named']);
    }

    /** @return list<array{0:string}> */
    public static function rejected(): array
    {
        return [
            'a space' => ['Iron Pick'],
            'punctuation' => ['Digger!'],
            'a hyphen' => ['iron-pick'],
            'an underscore' => ['iron_pick'],
            'an accent' => ['Björn'],
            'empty' => [''],
            'only spaces' => ['   '],
            'too short' => ['abc'],
            'too long' => ['abcdefghijklmnopq'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_a_name_that_is_not_letters_and_digits_is_refused(string $name): void
    {
        $this->expectException(GameException::class);

        $this->game->renameCharacter($this->character('wallet-one'), $name);
    }

    /**
     * Unnamed characters all read "Prospector", so one player owning it would
     * make every other unnamed prospector look like them.
     */
    public function test_the_unnamed_label_cannot_be_claimed(): void
    {
        $this->expectException(GameException::class);

        $this->game->renameCharacter($this->character('wallet-one'), 'prospector');
    }

    public function test_two_prospectors_cannot_hold_one_name(): void
    {
        $this->game->renameCharacter($this->character('wallet-one'), 'Digger7');

        $this->expectException(GameException::class);
        $this->game->renameCharacter($this->character('wallet-two'), 'Digger7');
    }

    /** The collation the index runs under is case-insensitive, and so is this. */
    public function test_a_name_is_taken_whatever_the_case(): void
    {
        $this->game->renameCharacter($this->character('wallet-one'), 'Digger7');

        $this->expectException(GameException::class);
        $this->game->renameCharacter($this->character('wallet-two'), 'dIGGER7');
    }

    /**
     * §7 -- a prospector names themselves ONCE.
     *
     * A name is drawn beside other players' on a shared map, and one that can
     * change is one nobody can be recognised by. The refusal covers the name
     * they already hold as well as any other: there is no second naming, not
     * even a harmless one.
     */
    public function test_a_name_is_taken_once_and_never_again(): void
    {
        $character = $this->character('wallet-one');
        $this->game->renameCharacter($character, 'Digger7');

        foreach (['Delver9', 'Digger7'] as $attempt) {
            try {
                $this->game->renameCharacter($character->fresh(), $attempt);
                $this->fail("a second naming was allowed: {$attempt}");
            } catch (GameException $e) {
                $this->assertSame('name_spent', $e->errorCode);
            }
        }

        $this->assertSame('Digger7', $character->fresh()->name);
    }

    /**
     * The column IS the record of whether the naming has been spent, so the
     * flag the client reads is the same fact rather than a second copy of it.
     */
    public function test_the_state_says_whether_the_naming_is_still_owed(): void
    {
        $character = $this->character('wallet-one');

        $this->assertFalse($this->game->playerState($character)['character']['named']);

        $this->game->renameCharacter($character, 'Digger7');

        $this->assertTrue($this->game->playerState($character->fresh())['character']['named']);
    }

    /** Many may be unnamed at once -- which is what the nullable column is for. */
    public function test_any_number_of_prospectors_may_be_unnamed(): void
    {
        $this->character('wallet-one');
        $this->character('wallet-two');
        $this->character('wallet-three');

        $this->assertSame(3, Character::whereNull('name')->count());
    }

    /**
     * The screen prints the limits under the field, so it carries its own copy
     * of them. This is what stops the copy drifting from the rule it describes.
     */
    public function test_the_client_mirrors_the_name_limits(): void
    {
        $mirror = file_get_contents(base_path('resources/js/game/balance.ts'));

        $this->assertMatchesRegularExpression(
            '/nameMin:\s*'.Balance::CHARACTER_NAME_MIN.'\b/',
            $mirror,
            'resources/js/game/balance.ts disagrees with Balance::CHARACTER_NAME_MIN',
        );
        $this->assertMatchesRegularExpression(
            '/nameMax:\s*'.Balance::CHARACTER_NAME_MAX.'\b/',
            $mirror,
            'resources/js/game/balance.ts disagrees with Balance::CHARACTER_NAME_MAX',
        );
    }

    private function character(string $wallet): Character
    {
        return $this->game->createCharacter(Player::create(['wallet' => $wallet]));
    }
}
