<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §12 -- the tutorial cursor, removed. Quests are the tutorial now.
 *
 * The eleven scripted steps were always the real game loop, so they became the
 * first eight rows of Quests::DEFS without losing a lesson -- and gained a
 * reason to finish, which a prompt paying nothing never had. One place a player
 * looks to find out what to do next, rather than two.
 *
 * The column goes rather than being left dormant: a cursor nothing advances is
 * a field that reads as true and is not, which is the same argument that took
 * the action-point columns out (§7.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('tutorial_step');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->integer('tutorial_step')->default(0);
        });
    }
};
