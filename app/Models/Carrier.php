<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §9.5.7 -- a marked enemy holding somebody's row.
 *
 * @property int $id
 * @property int $col
 * @property int $row
 * @property string $monster_key
 * @property int $owner_character_id
 * @property array $loot
 * @property string $label
 * @property int $expires_at
 */
class Carrier extends Model
{
    protected $fillable = [
        'col', 'row', 'monster_key', 'owner_character_id', 'loot', 'label', 'expires_at',
    ];

    protected $casts = [
        'col' => 'integer',
        'row' => 'integer',
        'owner_character_id' => 'integer',
        'loot' => 'array',
        'expires_at' => 'integer',
    ];

    /** @return BelongsTo<Character,Carrier> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'owner_character_id');
    }
}
