<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §8.5 -- a charge from a consumable, waiting on the action it names.
 *
 * There is no clock on it. A row exists means the effect is armed; taking the
 * action it is scoped to applies it and deletes the row. That is what makes an
 * hour offline and an hour idle identical (§16) without a deadline to compare
 * against -- there is simply nothing to expire.
 */
class CharacterBuff extends Model
{
    protected $fillable = ['character_id', 'item_key', 'stat', 'scope', 'value'];

    protected $casts = ['value' => 'float'];
}
