<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §9.5.3 -- how far along a journey the road has been checked for packs.
 *
 * Interception is lazy: a walk is not simulated hex by hex as it happens, it is
 * caught up whenever the character is next read. Without a high-water mark that
 * catch-up would rescan the whole road every request, which on a two-hundred hex
 * walk is two hundred tile generations per poll.
 *
 * It is on the character rather than a travel table because a character has at
 * most one journey, exactly like the four columns it sits beside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('travel_scanned_hexes')
                ->default(0)
                ->comment('§9.5.3 how many hexes of the current road have been checked for packs');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('travel_scanned_hexes');
        });
    }
};
