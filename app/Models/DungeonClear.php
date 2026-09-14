<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §9.6.2 -- a monster that has fallen, and does not come back.
 *
 * One row per cleared hex, with the unique index doing the work: monsters never
 * respawn, so the row's existence IS the state, and the floor's kill count is
 * COUNT(*) over these rather than a tally anybody has to keep in step.
 *
 * @property int $id
 * @property int $dungeon_session_id
 * @property int $floor
 * @property int $col
 * @property int $row
 * @property int $character_id
 * @property bool $guardian
 * @property int $killed_at_ms
 */
class DungeonClear extends Model
{
    /**
     * The row carries `killed_at_ms`, which is the clock everything else in the
     * game is written in (§16's server-authoritative timers are integer ms).
     * Laravel's own pair would be a second answer to when this happened, in a
     * different unit, for nobody to read.
     */
    public $timestamps = false;

    protected $fillable = [
        'dungeon_session_id', 'floor', 'col', 'row', 'character_id', 'guardian', 'killed_at_ms',
    ];

    protected $casts = [
        'floor' => 'integer',
        'col' => 'integer',
        'row' => 'integer',
        'guardian' => 'boolean',
        'killed_at_ms' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(DungeonSession::class, 'dungeon_session_id');
    }
}
