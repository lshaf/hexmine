<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterSkill extends Model
{
    public $timestamps = false;

    protected $fillable = ['character_id', 'skill_key', 'level', 'xp'];

    protected $casts = ['level' => 'integer', 'xp' => 'integer'];
}
