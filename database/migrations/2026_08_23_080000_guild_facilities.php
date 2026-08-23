<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §10.5 -- what a guild's gold is for.
 *
 * `guilds.gold` has existed since founding and nothing has ever spent it. These
 * two columns are what it buys: seats in the hall, and rungs on the bench.
 *
 * Both start at zero. A guild is founded with a hall and no facilities in it --
 * building the first one is the roster's first job together, which is the whole
 * argument for the treasury existing at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->unsignedTinyInteger('hall_level')
                ->default(0)
                ->comment('§10.5 seats, over the flat base');

            $table->unsignedTinyInteger('bench_level')
                ->default(0)
                ->comment('§10.5 rungs past what the settlement itself reaches');
        });

        Schema::table('guild_members', function (Blueprint $table) {
            // §10.2 -- a running total rather than a ledger of rows. What the
            // guild needs to know is who carried it, and that is one number;
            // when the donation happened is nobody's decision.
            $table->unsignedBigInteger('donated')
                ->default(0)
                ->comment('§10.5 gold this member has put in, ever');
        });
    }

    public function down(): void
    {
        Schema::table('guild_members', function (Blueprint $table) {
            $table->dropColumn('donated');
        });

        Schema::table('guilds', function (Blueprint $table) {
            $table->dropColumn(['hall_level', 'bench_level']);
        });
    }
};
