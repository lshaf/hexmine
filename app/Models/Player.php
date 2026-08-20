<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A wallet. §7: one character per wallet, soulbound -- the hasOne is the schema
 * expressing that rule, not a convenience.
 */
class Player extends Model
{
    protected $fillable = ['wallet', 'session_id', 'eligible_since'];

    protected $casts = ['eligible_since' => 'integer'];

    public function character(): HasOne
    {
        return $this->hasOne(Character::class);
    }

    /**
     * §2 -- a wallet must hold a minimum balance for 7 continuous days before it
     * can act. There is no chain integration yet, so this is the seam: when
     * wallet connect lands, set eligible_since from on-chain history and this
     * starts biting without any caller changing.
     */
    public function isEligible(int $now): bool
    {
        if ($this->eligible_since === null) {
            return true;
        }

        return $now - $this->eligible_since >= 7 * 24 * 60 * 60 * 1000;
    }
}
