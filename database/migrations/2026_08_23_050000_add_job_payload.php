<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §9.5.5 -- what a job locked in when it started.
 *
 * Every other job on this table records its decision in a column: a trip stores
 * the material the tool could reach, a craft stores what is on the bench. A
 * fight decides more than one thing at once -- which way it went, and the pair
 * of numbers it went that way on -- and none of those are a column anybody else
 * would ever use.
 *
 * Recorded at the START for the same reason a trip records its material there:
 * the kit that took the fight is the kit that fought it, and swapping to a
 * better sword while the timer runs must buy nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs_queue', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('presence');
        });
    }

    public function down(): void
    {
        Schema::table('jobs_queue', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
};
