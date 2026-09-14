<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §9.6 -- a dungeon session, its roster, and what it has killed.
 *
 * Three tables, and what is NOT here is the floor: where every monster stands is
 * a pure function of the seed (`Dungeons`), so twenty-five hundred hexes a floor
 * across ten floors is nought rows. What has to be written down is only what the
 * hash cannot know -- who is in, where they are standing, and what has already
 * fallen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dungeon_sessions', function (Blueprint $table) {
            $table->id();

            // §9.6.1 -- what a player types to join. Shareable by design, so it
            // carries no authority of its own: standing at the mouth is the
            // other half, and the roster locks at the first descent.
            $table->string('code', 12)->unique();

            // §9.6.2 -- 128 bits of CSPRNG, folded into every placement draw.
            //
            // The one column in the game that must never reach a client. It is
            // what stops a party deriving where every monster on the floor is
            // standing from four things they already hold, and unlike a bug it
            // does not fail loudly when it leaks -- it just quietly stops being
            // a secret. Hidden on the model, and there is a test.
            $table->string('secret', 64);

            $table->string('dungeon', 32);
            $table->string('category', 16);
            $table->string('difficulty', 16);

            $table->foreignId('owner_character_id')->constrained('characters')->cascadeOnDelete();

            // §9.6.2 -- whoever walked in first, which is part of the floor seed.
            // Null until somebody does: a session that has been opened and not
            // entered has no floors yet.
            $table->unsignedBigInteger('first_character_id')->nullable();

            $table->bigInteger('created_at_ms');
            $table->bigInteger('expires_at_ms');

            // §9.6.1 -- set at the first descent, after which nobody else joins.
            // A session joinable at any depth is a CARRY: clear to floor nine,
            // let a fresh wallet in on the code, and it collects the floor-ten
            // roll having fought nothing.
            $table->bigInteger('locked_at_ms')->nullable();

            $table->timestamps();
            $table->index(['expires_at_ms']);
        });

        Schema::create('dungeon_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dungeon_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('floor')->default(1);
            $table->unsignedSmallInteger('col')->default(0);
            $table->unsignedSmallInteger('row')->default(0);

            // Null while they are still at the mouth; set when they descend.
            $table->bigInteger('entered_at_ms')->nullable();

            // §5.6 -- the walk costs what it costs out in the world, five
            // seconds a hex, and a step inside is not free either. One column
            // rather than a path: a step is one hex, so there is never a road
            // to interpolate along, only a hex you are on and a moment you may
            // act again.
            $table->bigInteger('busy_until_ms')->nullable();

            $table->timestamps();

            // A member row OUTLIVES its session, which is what makes §9.6.8's
            // weekly cap countable: a unique index on character_id would be one
            // live membership and no history at all, so "one session at a time"
            // is checked against a session that is still live instead.
            $table->unique(['dungeon_session_id', 'character_id']);
            $table->index('character_id');
        });

        Schema::create('dungeon_clears', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dungeon_session_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('floor');
            $table->unsignedSmallInteger('col');
            $table->unsignedSmallInteger('row');

            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->boolean('guardian')->default(false);

            $table->bigInteger('killed_at_ms');

            // §9.6.2 -- monsters never respawn, and this index is what makes that
            // true rather than a rule in code: two members closing on one hex at
            // once is exactly the race a check loses and a unique key wins.
            //
            // It is also what makes the kill count DERIVED. The gate is "six have
            // fallen on this floor", and that is COUNT(*) over these rows --
            // storing the tally beside them would be a second opinion about one
            // fact, which §12.1 already refuses for a quest goal.
            $table->unique(['dungeon_session_id', 'floor', 'col', 'row']);
            $table->index(['dungeon_session_id', 'floor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dungeon_clears');
        Schema::dropIfExists('dungeon_members');
        Schema::dropIfExists('dungeon_sessions');
    }
};
