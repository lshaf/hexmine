<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One quest's standing for one character.
 *
 * `progress` is only meaningful for a counted goal (Quests::COUNTED); a measured
 * one is read off the character every time it is asked about, so writing it down
 * would be a second opinion about a level that already exists.
 *
 * `claimed_at` is the whole state machine. Unset and short of the target is
 * pending; unset and at the target is ready; set is done and never comes back.
 */
class CharacterQuest extends Model
{
    protected $fillable = ['character_id', 'quest_key', 'progress', 'claimed_at'];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'claimed_at' => 'integer',
        ];
    }
}
