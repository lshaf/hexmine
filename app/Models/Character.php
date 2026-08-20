<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Character extends Model
{
    protected $fillable = [
        'player_id', 'name', 'level', 'xp', 'ap', 'ap_updated_at', 'gold',
        'col', 'row', 'presence_settlement_id', 'tutorial_step', 'last_decay_at',
        'travel_to_col', 'travel_to_row', 'travel_started_at', 'travel_ends_at',
    ];

    protected $casts = [
        'level' => 'integer',
        'xp' => 'integer',
        'ap' => 'integer',
        'ap_updated_at' => 'integer',
        'gold' => 'integer',
        'col' => 'integer',
        'row' => 'integer',
        'tutorial_step' => 'integer',
        'last_decay_at' => 'integer',
        'travel_to_col' => 'integer',
        'travel_to_row' => 'integer',
        'travel_started_at' => 'integer',
        'travel_ends_at' => 'integer',
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

    public function jobs(): HasMany
    {
        return $this->hasMany(GameJob::class);
    }
}
