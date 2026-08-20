<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterMaterial extends Model
{
    public $timestamps = false;

    protected $fillable = ['character_id', 'material_key', 'quantity'];

    protected $casts = ['quantity' => 'integer'];
}
