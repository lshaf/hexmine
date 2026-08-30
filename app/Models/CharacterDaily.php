<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One daily task's standing, for one character, on one day (§12.2).
 *
 * `day` is the whole difference between this and a quest. A CharacterQuest row
 * is a tally that lives forever; this one is scoped to the day it was earned
 * on, so nothing carries over and nothing needs sweeping up.
 *
 * `claimed_at` is the state machine, exactly as it is for a quest: unset and
 * short of the target is in progress, unset and at it is payable, set is done.
 * Unlike a quest, "done" only lasts until the day turns.
 */
class CharacterDaily extends Model
{
    protected $fillable = ['character_id', 'day', 'task_key', 'progress', 'claimed_at'];

    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'progress' => 'integer',
            'claimed_at' => 'integer',
        ];
    }
}
