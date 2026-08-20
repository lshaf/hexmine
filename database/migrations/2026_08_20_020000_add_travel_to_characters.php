<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel takes time, §5. A journey is a destination and two timestamps: the
 * hexes in between are derived from the same line function the client draws,
 * so the road itself is never stored.
 *
 * The character keeps standing on the tile it left until the journey lands.
 * Position is a fact about where you may act, and mid-stride you may not act
 * anywhere -- so there is no intermediate position worth persisting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('travel_to_col')->nullable();
            $table->unsignedInteger('travel_to_row')->nullable();
            $table->unsignedBigInteger('travel_started_at')->nullable();
            $table->unsignedBigInteger('travel_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['travel_to_col', 'travel_to_row', 'travel_started_at', 'travel_ends_at']);
        });
    }
};
