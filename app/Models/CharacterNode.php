<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §7.4.2 -- one bought tree node. Bought, never refunded: a respec would turn
 * the 100-point cap into a suggestion and let one character be every specialist
 * in turn, which is exactly what the cap exists to prevent.
 */
class CharacterNode extends Model
{
    protected $fillable = ['character_id', 'node_key'];
}
