<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8.5 -- a buff now names the action it applies to.
 *
 * The rule was "one buff per stat", enforced by a unique index on
 * (character_id, stat). With sixty action-scoped potions that rule would mean a
 * woodcutting-yield draught and a mining-yield draught could never be running
 * at once, which is the entire point of scoping them.
 *
 * So the rule becomes "one buff per stat PER ACTION". Its intent is unchanged
 * and still enforced by the schema rather than by code: drinking a second of
 * the same kind restarts the clock instead of stacking, so nobody can bank an
 * afternoon of potions into one window. What changes is that a character may
 * now have several buffs running, each on a different thing they do -- and the
 * ceiling on any one action is exactly what it was, because STAT_CEILING
 * clamps the aggregate for that action alone.
 *
 * Existing rows are unscoped, so they become 'global': a buff that applies
 * everywhere, which is what they were bought as.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_buffs', function (Blueprint $table) {
            $table->string('scope')->default('global')->after('stat');
        });

        // SQLite cannot drop an index it did not name, and Laravel's generated
        // name is the one both drivers agree on.
        Schema::table('character_buffs', function (Blueprint $table) {
            $table->dropUnique('character_buffs_character_id_stat_unique');
            $table->unique(['character_id', 'stat', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::table('character_buffs', function (Blueprint $table) {
            $table->dropUnique('character_buffs_character_id_stat_scope_unique');
        });

        // Two rows can differ only by scope, so collapsing back would violate
        // the old index. Keep the newest per (character, stat) and drop the rest.
        \Illuminate\Support\Facades\DB::table('character_buffs')
            ->whereNotIn('id', function ($q) {
                $q->selectRaw('MAX(id)')->from('character_buffs')->groupBy('character_id', 'stat');
            })
            ->delete();

        Schema::table('character_buffs', function (Blueprint $table) {
            $table->dropColumn('scope');
            $table->unique(['character_id', 'stat']);
        });
    }
};
