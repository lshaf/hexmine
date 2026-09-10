<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §10.6 -- a level that is being BUILT rather than one that has been bought.
 *
 * Upgrading used to land the instant it was paid for, which made the most
 * expensive thing a guild can do the only thing in the game with no clock on
 * it: carry the gold in, walk out with the level. A saw pit takes twelve
 * minutes (§6) and an anvil eight (§8.4); a guild raising a hall on a waste
 * cannot take none.
 *
 * Two columns rather than a jobs row, because a build belongs to the GUILD and
 * not to whoever pressed the button -- §10.5 already keeps the treasury and the
 * facilities on this table for the same reason. Nobody has to stand there, and
 * nobody has to come back: there is nothing to carry home, so it finishes on
 * its own (§16 -- an hour offline and an hour watching produce the same thing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            // Which ladder is being climbed: 'processing' or 'craft'. Null is a
            // guild with nothing under construction, which is most of them.
            $table->string('land_building', 16)
                ->nullable()
                ->after('land_craft_level');

            // Unix ms it is finished. Read against the clock rather than swept
            // by a worker: a build nobody has looked at is still finished.
            $table->unsignedBigInteger('land_built_at')
                ->nullable()
                ->after('land_building');
        });
    }

    public function down(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->dropColumn(['land_building', 'land_built_at']);
        });
    }
};
