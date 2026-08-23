<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §10.0.1 -- somebody asking to be let in.
 *
 * @property int $id
 * @property int $guild_id
 * @property int $character_id
 * @property int $applied_at
 */
class GuildApplication extends Model
{
    protected $fillable = ['guild_id', 'character_id', 'applied_at'];

    protected $casts = [
        'guild_id' => 'integer',
        'character_id' => 'integer',
        'applied_at' => 'integer',
    ];

    /** @return BelongsTo<Guild,GuildApplication> */
    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class);
    }

    /** @return BelongsTo<Character,GuildApplication> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
