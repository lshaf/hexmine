<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §10.6 -- a hex a guild bought, named and is levelling.
 *
 * Columns on `guilds` rather than a table of their own, because a guild holds
 * at most one: one row per guild is what makes that structural instead of a
 * rule somebody has to remember. Null land_col is a guild that has not claimed
 * yet, which is every guild the day it is founded.
 *
 * The two facilities level apart (§10.6), so they are two columns. Zero is the
 * honest starting value for both: a claim buys the ground and nothing standing
 * on it, and a processing line does not exist until it is levelled once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->integer('land_col')->nullable()->after('gold');
            $table->integer('land_row')->nullable()->after('land_col');

            // §10.6 -- the guild names its own ground. Not unique: two guilds
            // may both call their hex Hollow Reach, the way two people may
            // share a first name, because the guild's own name and code are
            // what identify it (§10.0.3) and this is a place rather than an
            // identity.
            $table->string('land_name', 32)->nullable()->after('land_row');

            $table->unsignedTinyInteger('land_processing_level')
                ->default(0)
                ->comment('§10.6 lines it runs, and the clock');

            $table->unsignedTinyInteger('land_craft_level')
                ->default(0)
                ->comment('§10.6 the rung its bench reaches');

            // One claim per hex, enforced by the index rather than by a check:
            // two guilds racing for the same waste is exactly the case a rule
            // in code loses and a unique index wins.
            $table->unique(['land_col', 'land_row']);
        });
    }

    public function down(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->dropUnique(['land_col', 'land_row']);
            $table->dropColumn([
                'land_col', 'land_row', 'land_name',
                'land_processing_level', 'land_craft_level',
            ]);
        });
    }
};
