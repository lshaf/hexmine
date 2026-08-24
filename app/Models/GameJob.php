<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A running mine or processing batch.
 *
 * Named GameJob, and tabled as `jobs_queue`, to stay clear of Laravel's own
 * queue `jobs` table -- these are gameplay timers, not background work.
 */
class GameJob extends Model
{
    protected $table = 'jobs_queue';

    protected $fillable = [
        'character_id', 'kind', 'status', 'col', 'row', 'slot', 'material_key',
        'settlement_id', 'recipe_key', 'output_key', 'presence', 'quantity',
        'skill_key', 'started_at', 'ends_at', 'payload',
    ];

    protected $casts = [
        'col' => 'integer',
        'row' => 'integer',
        'slot' => 'integer',
        'quantity' => 'integer',
        'presence' => 'boolean',
        'started_at' => 'integer',
        'ends_at' => 'integer',
        'payload' => 'array',
    ];

    /** Ready to collect. Derived from the clock, never from a stored flag. */
    public function isReady(int $now): bool
    {
        return $now >= $this->ends_at;
    }
}
