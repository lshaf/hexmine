<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line on the slate, §8.4.
 *
 * The key alone, because that is the whole of the fact. What it costs, what it
 * makes and which bench takes it are all in the catalog, and a copy here would
 * go stale the first time a recipe was tuned.
 */
class CharacterBookmark extends Model
{
    protected $fillable = ['character_id', 'recipe_key'];
}
