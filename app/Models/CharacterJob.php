<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §7.4.1 -- a job level gates tree nodes and does nothing else. It carries no
 * stat, no yield and no speed, which is why this model has no bonus of any kind
 * on it: there is nothing to read but the level itself.
 */
class CharacterJob extends Model
{
    protected $fillable = ['character_id', 'job_key', 'level', 'xp'];

    protected $casts = ['level' => 'integer', 'xp' => 'integer'];
}
