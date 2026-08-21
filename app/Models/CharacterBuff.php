<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §8.5 -- a timed effect from a consumable.
 *
 * `expires_at` is an absolute server-clock deadline. Nothing ticks it down;
 * whether a buff is live is decided by comparing it against now, which is what
 * keeps an hour offline and an hour idle identical (§16).
 */
class CharacterBuff extends Model
{
    protected $fillable = ['character_id', 'item_key', 'stat', 'value', 'expires_at'];

    protected $casts = ['value' => 'float', 'expires_at' => 'integer'];

    public function isLive(int $now): bool
    {
        return $this->expires_at > $now;
    }
}
