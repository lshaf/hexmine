<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterItem extends Model
{
    protected $fillable = ['character_id', 'item_key', 'durability', 'equipped'];

    protected $casts = ['durability' => 'integer', 'equipped' => 'boolean'];
}
