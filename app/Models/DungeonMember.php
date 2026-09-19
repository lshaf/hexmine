<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §9.6.1 -- one prospector in one session, and where they are standing in it.
 *
 * @property int $id
 * @property int $dungeon_session_id
 * @property int $character_id
 * @property int $floor
 * @property int $col
 * @property int $row
 * @property int|null $entered_at_ms
 */
class DungeonMember extends Model
{
    protected $fillable = [
        'dungeon_session_id', 'character_id', 'floor', 'col', 'row', 'entered_at_ms', 'busy_until_ms',
        'walk_to_col', 'walk_to_row', 'walk_started_ms', 'walk_ends_ms',
    ];

    protected $casts = [
        'floor' => 'integer',
        'col' => 'integer',
        'row' => 'integer',
        'entered_at_ms' => 'integer',
        'busy_until_ms' => 'integer',
        'walk_to_col' => 'integer',
        'walk_to_row' => 'integer',
        'walk_started_ms' => 'integer',
        'walk_ends_ms' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(DungeonSession::class, 'dungeon_session_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** Still at the mouth rather than on a floor. */
    public function isInside(): bool
    {
        return $this->entered_at_ms !== null;
    }

    /** Mid-step. Every verb inside refuses while this is true, the road included. */
    public function isBusy(int $now): bool
    {
        return ($this->busy_until_ms !== null && $now < $this->busy_until_ms)
            || $this->isWalking($now);
    }

    /** §9.6 -- on the road between two hexes of a floor. */
    public function isWalking(int $now): bool
    {
        return $this->walk_ends_ms !== null && $now < $this->walk_ends_ms;
    }
}
