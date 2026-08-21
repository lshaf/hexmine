<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterItem extends Model
{
    protected $fillable = ['character_id', 'item_key', 'durability', 'equipped', 'options'];

    /** §8.0.1 -- `options` is the rolled bonus lines, a list of {stat, value}. */
    protected $casts = [
        'durability' => 'integer',
        'equipped' => 'boolean',
        'options' => 'array',
    ];
}
