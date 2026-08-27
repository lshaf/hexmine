<?php

declare(strict_types=1);

namespace App\Models;

use App\Game\Catalog;
use Illuminate\Database\Eloquent\Model;

class CharacterItem extends Model
{
    protected $fillable = ['character_id', 'item_key', 'durability', 'max_durability', 'equipped', 'options'];

    /** §8.0.1 -- `options` is the rolled bonus lines, a list of {stat, value}. */
    protected $casts = [
        'durability' => 'integer',
        'max_durability' => 'integer',
        'equipped' => 'boolean',
        'options' => 'array',
    ];

    /**
     * §7.4.3 -- this piece's own ceiling, which is not always the catalog's.
     *
     * `craftDurability` raises the MAX of what a Smith makes, so two copies of
     * one recipe can have different ceilings. Null means nobody moved it: the
     * piece was bought, looted, or made by somebody who had not bought the node.
     *
     * Everything that reads a ceiling for an OWNED piece has to come through
     * here. Reading the catalog directly is what made the bonus last exactly one
     * repair -- the mend filled it to the catalog max and the extra was gone.
     */
    public function maxDurability(): int
    {
        return $this->max_durability
            ?: (int) (Catalog::item($this->item_key)['maxDurability'] ?? 0);
    }
}
