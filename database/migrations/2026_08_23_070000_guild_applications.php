<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §10.0.1 -- the door, as one setting with three positions.
 *
 * It was a boolean, and a boolean could only say open or shut. A guild that
 * wants to see who is knocking is a third thing, and expressing it as a second
 * flag alongside the first would have made four states out of three -- with
 * "closed, but vetting" meaning nothing at all.
 *
 *   closed    not listed, nobody gets in
 *   open      listed, and walking in is enough
 *   approval  listed, and the owner decides
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->string('recruitment')
                ->default('open')
                ->after('founder_character_id')
                ->comment('§10.0.1 closed | open | approval');
        });

        // Founded before the setting existed: an open door stays open.
        DB::table('guilds')
            ->update(['recruitment' => DB::raw(
                "CASE WHEN recruiting = 1 THEN 'open' ELSE 'closed' END",
            )]);

        Schema::table('guilds', function (Blueprint $table) {
            // The index goes first: SQLite refuses to drop a column something
            // is still indexed on, and says so in a way that reads as a typo.
            $table->dropIndex(['recruiting']);
            $table->dropColumn('recruiting');
        });

        Schema::table('guilds', function (Blueprint $table) {
            $table->index('recruitment');
        });

        Schema::create('guild_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guild_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('applied_at');
            $table->timestamps();

            // One application per door. Asking twice is asking once.
            $table->unique(['guild_id', 'character_id']);
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_applications');

        Schema::table('guilds', function (Blueprint $table) {
            $table->boolean('recruiting')->default(true);
        });

        DB::table('guilds')
            ->update(['recruiting' => DB::raw(
                "CASE WHEN recruitment = 'closed' THEN 0 ELSE 1 END",
            )]);

        Schema::table('guilds', function (Blueprint $table) {
            $table->dropIndex(['recruitment']);
            $table->dropColumn('recruitment');
        });

        Schema::table('guilds', function (Blueprint $table) {
            $table->index('recruiting');
        });
    }
};
