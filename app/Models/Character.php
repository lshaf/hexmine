<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Character extends Model
{
    protected $fillable = [
        'player_id', 'name', 'level', 'xp', 'gold',
        'col', 'row', 'presence_settlement_id',
        'travel_to_col', 'travel_to_row', 'travel_started_at', 'travel_ends_at',
        'travel_scanned_hexes',
    ];

    protected $casts = [
        'level' => 'integer',
        'xp' => 'integer',
        'gold' => 'integer',
        'col' => 'integer',
        'row' => 'integer',
        'travel_to_col' => 'integer',
        'travel_to_row' => 'integer',
        'travel_started_at' => 'integer',
        'travel_ends_at' => 'integer',
        'travel_scanned_hexes' => 'integer',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(CharacterMaterial::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(CharacterSkill::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CharacterItem::class);
    }

    /** §8.5 -- stackable, never equipped. */
    public function consumables(): HasMany
    {
        return $this->hasMany(CharacterConsumable::class);
    }

    /** §8.5 -- timed effects. Expiry is compared, never ticked. */
    public function buffs(): HasMany
    {
        return $this->hasMany(CharacterBuff::class);
    }

    /**
     * §7.4.1 -- one row per job, level only. Gates nodes, grants nothing.
     *
     * Deliberately not `jobs()`: that name is already taken by the running
     * mining and processing tasks above, and the two would be a nasty thing to
     * confuse. A GameJob is work in progress; a CharacterJob is a trade you have
     * levelled.
     */
    public function jobLevels(): HasMany
    {
        return $this->hasMany(CharacterJob::class);
    }

    /** §7.4.2 -- the tree nodes bought. Spent points is the count of these. */
    public function skillRanks(): HasMany
    {
        return $this->hasMany(CharacterSkillRank::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(CharacterNode::class);
    }

    /** §12.1 -- one row per quest this character has touched. */
    public function quests(): HasMany
    {
        return $this->hasMany(CharacterQuest::class);
    }

    /** §12.2 -- one row per daily task touched, scoped to the day it was earned on. */
    public function dailies(): HasMany
    {
        return $this->hasMany(CharacterDaily::class);
    }

    /** §8.4 -- the slate: ten recipes this character means to make. */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(CharacterBookmark::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(GameJob::class);
    }
}
