<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §10.0 -- a guild and its hall.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $description
 * @property string|null $flag
 * @property string $settlement_id
 * @property int $col
 * @property int $row
 * @property int $founder_character_id
 * @property string $recruitment
 * @property int $gold
 * @property int $hall_level
 * @property int $bench_level
 */
class Guild extends Model
{
    /** §10.0.1 -- not listed, and nobody gets in. */
    public const CLOSED = 'closed';

    /** Listed, and walking in is enough. */
    public const OPEN = 'open';

    /** Listed, and the owner decides who comes through. */
    public const APPROVAL = 'approval';

    public const DOORS = [self::CLOSED, self::OPEN, self::APPROVAL];

    protected $fillable = [
        'name', 'code', 'description', 'flag', 'settlement_id', 'col', 'row',
        'founder_character_id', 'recruitment', 'gold', 'hall_level', 'bench_level',
    ];

    protected $casts = [
        'col' => 'integer',
        'row' => 'integer',
        'founder_character_id' => 'integer',
        'gold' => 'integer',
        'hall_level' => 'integer',
        'bench_level' => 'integer',
    ];

    /** @return HasMany<GuildMember> */
    public function members(): HasMany
    {
        return $this->hasMany(GuildMember::class);
    }

    /** @return HasMany<GuildApplication> */
    public function applications(): HasMany
    {
        return $this->hasMany(GuildApplication::class);
    }
}
