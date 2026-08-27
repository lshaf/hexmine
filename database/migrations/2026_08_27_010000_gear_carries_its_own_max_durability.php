<?php

declare(strict_types=1);

use App\Game\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §7.4.3 -- `craftDurability` raises a thing's MAX, not its current fill.
 *
 * It was writing the bonus into `durability` while the ceiling stayed the
 * catalog's, which made the node worth one craft and then nothing: the bar read
 * past 100%, resale clamped the fraction back to 1, and the first repair set
 * durability to the catalog max and threw the bonus away for good. A Smith deep
 * enough in the tree to buy it got a piece that was better exactly until it was
 * mended once.
 *
 * So an owned piece carries its own ceiling. Null means "whatever the catalog
 * says", which is every piece that was bought, looted or made without the node.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_items', function (Blueprint $table) {
            $table->unsignedInteger('max_durability')->nullable()->after('durability');
        });

        // Anything already over its catalog ceiling was made by a Smith who had
        // paid for it. Keep what they earned rather than clamping it away on
        // their next repair -- the bug took the bonus, the fix should not.
        foreach (DB::table('character_items')->select('id', 'item_key', 'durability')->cursor() as $row) {
            $catalog = (int) (Catalog::item($row->item_key)['maxDurability'] ?? 0);
            if ($catalog > 0 && (int) $row->durability > $catalog) {
                DB::table('character_items')
                    ->where('id', $row->id)
                    ->update(['max_durability' => (int) $row->durability]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('character_items', function (Blueprint $table) {
            $table->dropColumn('max_durability');
        });
    }
};
