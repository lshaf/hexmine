<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The map is centered on (0, 0), so half of every axis is negative.
 *
 * Balance::MAP_COLS is a RADIUS -- 200 means every column from -200 to 200
 * inclusive -- which makes a negative coordinate the ordinary case rather than
 * an edge one: a character standing anywhere west or north of the middle has
 * one. The four columns that carry a character's position were nonetheless
 * declared unsigned, and had been since the first migration.
 *
 * SQLite has no unsigned integer, so it stored -200 without complaint and the
 * mistake was invisible for as long as the project ran on it. MySQL does have
 * one, and refuses the write outright (1264) -- which means on MySQL a
 * character could occupy exactly the south-east quadrant of the map and
 * nothing else.
 *
 * tile_states, carriers and guilds already declare their col/row signed. This
 * brings the character's own position and its destination into line with them,
 * which is where they should have been to begin with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->integer('col')->change();
            $table->integer('row')->change();
            $table->integer('travel_to_col')->nullable()->change();
            $table->integer('travel_to_row')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('col')->change();
            $table->unsignedInteger('row')->change();
            $table->unsignedInteger('travel_to_col')->nullable()->change();
            $table->unsignedInteger('travel_to_row')->nullable()->change();
        });
    }
};
