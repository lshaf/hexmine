<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The only persisted map state: when a worked-out tile regrows (§5.1).
 * Rows exist only for tiles somebody has actually depleted.
 */
class TileState extends Model
{
    public $timestamps = false;

    protected $fillable = ['col', 'row', 'regrows_at'];

    protected $casts = ['col' => 'integer', 'row' => 'integer', 'regrows_at' => 'integer'];
}
