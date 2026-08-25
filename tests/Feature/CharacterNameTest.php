<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\GameException;
use App\Game\GameService;
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

    public function test_a_prospector_starts_unnamed_and_is_shown_the_label(): void
    {
        $character = $this->character('wallet-one');

        $this->assertNull($character->name);

        $state = $this->game->playerState($character);
        $this->assertSame('Prospector', $state['character']['name']);
        $this->assertFalse($state['character']['named']);
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

    /** Renaming to what you already are is not a collision with yourself. */
    public function test_keeping_your_own_name_is_allowed(): void
    {
        $character = $this->character('wallet-one');
        $this->game->renameCharacter($character, 'Digger7');

        $again = $this->game->renameCharacter($character->fresh(), 'Digger7');

        $this->assertSame('Digger7', $again->name);
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
