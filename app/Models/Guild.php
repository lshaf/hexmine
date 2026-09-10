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
 * @property int|null $land_col
 * @property int|null $land_row
 * @property string|null $land_name
 * @property int $land_processing_level
 * @property int $land_craft_level
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
        'land_col', 'land_row', 'land_name', 'land_processing_level', 'land_craft_level',
    ];

    protected $casts = [
        'col' => 'integer',
        'row' => 'integer',
        'founder_character_id' => 'integer',
        'gold' => 'integer',
        'hall_level' => 'integer',
        'bench_level' => 'integer',
        'land_col' => 'integer',
        'land_row' => 'integer',
        'land_processing_level' => 'integer',
        'land_craft_level' => 'integer',
    ];

    /** §10.6 -- has this guild put a flag on a hex yet? */
    public function hasLand(): bool
    {
        return $this->land_col !== null && $this->land_row !== null;
    }

    /**
     * §10.6 -- the land as a SETTLEMENT, so every existing path works on it.
     *
     * Processing, crafting, the queue, the bench fee and the station panel all
     * take a settlement shaped like WorldGen::settlementAt's, so the cheapest
     * and most honest way to make a guild's ground a place you can work is to
     * answer in that shape. `tier` is `guild`, which §8.0's own rarity cap
     * already names.
     *
     * The lines are the FIRST n of the five rather than a roll, and that is
     * deliberate: a capital's four are drawn from the pool because which one it
     * lacks is a fact about that capital (§6), where a guild's are bought in a
     * known order so a roster can plan which level opens the line they need.
     *
     * @return array<string,mixed>|null
     */
    public function landSettlement(): ?array
    {
        if (! $this->hasLand()) {
            return null;
        }

        $lines = \App\Game\Balance::guildLandLines($this->land_processing_level);

        return [
            'id' => "g_{$this->land_col}_{$this->land_row}",
            'name' => $this->land_name ?: $this->name,
            'tier' => 'guild',
            'col' => (int) $this->land_col,
            'row' => (int) $this->land_row,
            'lines' => array_slice(\App\Game\Catalog::SKILLS, 0, $lines),
            // §10.6 -- everything a caller needs to know that a worldgen
            // settlement would never carry.
            'guildId' => (int) $this->id,
            'guildName' => $this->name,
            'guildCode' => $this->code,
            'processingLevel' => (int) $this->land_processing_level,
            'craftLevel' => (int) $this->land_craft_level,
            'craftCap' => \App\Game\Balance::guildLandCraftCap($this->land_craft_level),
            'glyphTier' => \App\Game\Balance::guildLandGlyphTier(
                (int) $this->land_processing_level,
                (int) $this->land_craft_level,
            ),
        ];
    }

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
