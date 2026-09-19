<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §9.6 -- walking a floor is a JOURNEY, not a series of presses.
 *
 * It was one hex at a time with the client pacing itself, which meant the
 * marker jumped from tile to tile while the overworld's slid along a road. §5.6
 * already has the shape: a departure, a destination, a clock, and a position
 * derived along the line -- so a floor uses the same one rather than a second
 * kind of movement.
 *
 * `busy_until_ms` stays and keeps its own job: it is what a FIGHT sets, and a
 * fight is not a walk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dungeon_members', function (Blueprint $table) {
            $table->unsignedSmallInteger('walk_to_col')->nullable()->after('row');
            $table->unsignedSmallInteger('walk_to_row')->nullable()->after('walk_to_col');
            $table->bigInteger('walk_started_ms')->nullable()->after('walk_to_row');
            $table->bigInteger('walk_ends_ms')->nullable()->after('walk_started_ms');
        });
    }

    public function down(): void
    {
        Schema::table('dungeon_members', function (Blueprint $table) {
            $table->dropColumn(['walk_to_col', 'walk_to_row', 'walk_started_ms', 'walk_ends_ms']);
        });
    }
};
