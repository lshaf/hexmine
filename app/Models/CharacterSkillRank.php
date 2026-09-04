<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §7.4 -- one skill, held at a rank.
 *
 * The rank is a COUNT, not a set, and that is what makes the ladder structural:
 * you cannot hold rank 3 without rank 2, because 3 includes 2. The node table
 * this replaced needed code to stop somebody buying a capstone first.
 *
 * Bought, never refunded. A respec would turn the point cap into a suggestion
 * and let one character be every specialist in turn, which is exactly what the
 * cap exists to prevent (§7.4.2).
 */
class CharacterSkillRank extends Model
{
    protected $fillable = ['character_id', 'skill_key', 'rank'];

    protected $casts = ['rank' => 'integer'];
}
