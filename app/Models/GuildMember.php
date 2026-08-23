<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §10.0.2 -- one character's place in one guild.
 *
 * @property int $id
 * @property int $guild_id
 * @property int $character_id
 * @property string $role
 * @property int $joined_at
 * @property int $donated
 */
class GuildMember extends Model
{
    public const OWNER = 'owner';

    public const OFFICER = 'officer';

    public const MEMBER = 'member';

    protected $fillable = ['guild_id', 'character_id', 'role', 'joined_at', 'donated'];

    protected $casts = [
        'guild_id' => 'integer',
        'character_id' => 'integer',
        'joined_at' => 'integer',
        'donated' => 'integer',
    ];

    /** @return BelongsTo<Guild,GuildMember> */
    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class);
    }

    /** @return BelongsTo<Character,GuildMember> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
