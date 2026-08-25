<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §8.5 -- a buff now names the action it applies to.
 *
 * The rule was "one buff per stat", enforced by a unique index on
 * (character_id, stat). With sixty action-scoped potions that rule would mean a
 * woodcutting-yield draft and a mining-yield draft could never be running
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

        // The new index goes on BEFORE the old one comes off, and the order is
        // not tidiness. On MySQL the old unique is the only index covering the
        // character_id foreign key, and InnoDB refuses to drop the last index a
        // constraint is leaning on (1553). The wider unique leads with the same
        // column, so once it exists the constraint has somewhere else to stand.
        //
        // SQLite cannot drop an index it did not name, and Laravel's generated
        // name is the one both drivers agree on.
        Schema::table('character_buffs', function (Blueprint $table) {
            $table->unique(['character_id', 'stat', 'scope']);
        });

        Schema::table('character_buffs', function (Blueprint $table) {
            $table->dropUnique('character_buffs_character_id_stat_unique');
        });
    }

    public function down(): void
    {
        // Two rows can differ only by scope, so collapsing back would violate
        // the old index. Keep the newest per (character, stat) and drop the rest.
        //
        // The survivors are read out first rather than named in a subquery on
        // the table being deleted from: MySQL refuses that outright (1093), and
        // wrapping it in a derived table would only hide the same scan behind a
        // temporary copy. A rollback of a dev database is small enough to hold.
        $keep = DB::table('character_buffs')
            ->selectRaw('MAX(id) as id')
            ->groupBy('character_id', 'stat')
            ->pluck('id')
            ->all();

        DB::table('character_buffs')->whereNotIn('id', $keep)->delete();

        // Narrow index back on first, wide one off after -- the 1553 argument
        // from up(), read backwards.
        Schema::table('character_buffs', function (Blueprint $table) {
            $table->unique(['character_id', 'stat']);
        });

        Schema::table('character_buffs', function (Blueprint $table) {
            $table->dropUnique('character_buffs_character_id_stat_scope_unique');
            $table->dropColumn('scope');
        });
    }
};
