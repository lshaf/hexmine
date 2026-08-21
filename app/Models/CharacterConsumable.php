<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** §8.5 -- a stack of potions. Never equipped, so never a CharacterItem. */
class CharacterConsumable extends Model
{
    protected $fillable = ['character_id', 'item_key', 'quantity'];

    protected $casts = ['quantity' => 'integer'];
}
