<?php

declare(strict_types=1);

namespace App\Models;

use App\Game\Balance;
use App\Game\Dungeons;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §9.6.1 -- a dungeon session: a code, a roster, and twelve hours.
 *
 * @property int $id
 * @property string $code
 * @property string $secret
 * @property string $dungeon
 * @property string $category
 * @property string $difficulty
 * @property int $owner_character_id
 * @property int|null $first_character_id
 * @property int $created_at_ms
 * @property int $expires_at_ms
 * @property int|null $locked_at_ms
 */
class DungeonSession extends Model
{
    protected $fillable = [
        'code', 'secret', 'dungeon', 'category', 'difficulty',
        'owner_character_id', 'first_character_id',
        'created_at_ms', 'expires_at_ms', 'locked_at_ms',
    ];

    protected $casts = [
        'created_at_ms' => 'integer',
        'expires_at_ms' => 'integer',
        'locked_at_ms' => 'integer',
        'first_character_id' => 'integer',
    ];

    /**
     * §9.6.2 -- the secret never leaves the server, and this is the belt to the
     * braces.
     *
     * `$hidden` keeps it out of every `toArray()` and every JSON response,
     * including the ones nobody thought about: a debug dump, an error payload
     * carrying the model, a relation eager-loaded into a state response. The
     * payloads are written by hand and could each be trusted to leave it out;
     * the whole point is that a leak here is silent, so the default has to be
     * safe rather than the discipline.
     */
    protected $hidden = ['secret'];

    public function members(): HasMany
    {
        return $this->hasMany(DungeonMember::class);
    }

    public function clears(): HasMany
    {
        return $this->hasMany(DungeonClear::class);
    }

    public function isLive(int $now): bool
    {
        return $now < $this->expires_at_ms;
    }

    /** §9.6.1 -- open until somebody descends, and closed to newcomers after. */
    public function acceptsNewcomers(int $now): bool
    {
        return $this->isLive($now)
            && $this->locked_at_ms === null
            && $this->members()->count() < Balance::DUNGEON_PARTY_MAX;
    }

    /**
     * §9.6.2 -- the seed for one floor of this session.
     *
     * `first_character_id` falls back to the owner only while nobody has entered
     * yet, which is the one window where no floor has been drawn and so nothing
     * can be made inconsistent by it.
     */
    public function floorSeed(int $floor): int
    {
        return Dungeons::floorSeed(
            $this->secret,
            $this->code,
            $this->created_at_ms,
            $this->first_character_id ?? $this->owner_character_id,
            $floor,
        );
    }

    /** §9.6.2 -- how many of this floor's monsters have fallen, across the roster. */
    public function killsOn(int $floor): int
    {
        return $this->clears()->where('floor', $floor)->count();
    }
}
