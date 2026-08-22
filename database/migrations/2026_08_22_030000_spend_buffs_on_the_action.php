<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8.5 -- a draught is a charge now, not a clock.
 *
 * A buff used to be bought and then raced: it started a 30-minute window, and
 * whether it paid out depended on how far you were standing from the work. A
 * woodcutting draught drunk in the mountains was simply thrown away, which made
 * the scoping added a migration ago a trap rather than a choice.
 *
 * So the buff waits instead. It arms the action it names and is spent the
 * moment that action is taken -- the first woodcutting trip after the draught,
 * whenever that is. The sink is unchanged in substance: a potion is still
 * consumed and its effect is still temporary, but what ends it is *use* rather
 * than a deadline the player cannot always reach.
 *
 * The unique index on (character_id, stat, scope) is what keeps this bounded:
 * a character can hold at most one charge per stat per action, so drinking an
 * afternoon of potions still banks nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_buffs', function (Blueprint $table) {
            $table->dropIndex('character_buffs_expires_at_index');
        });

        Schema::table('character_buffs', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('character_buffs', function (Blueprint $table) {
            $table->unsignedBigInteger('expires_at')->default(0)->after('value');
        });

        Schema::table('character_buffs', function (Blueprint $table) {
            $table->index('expires_at');
        });
    }
};
