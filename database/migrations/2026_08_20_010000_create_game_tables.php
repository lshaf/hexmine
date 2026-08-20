<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Game schema.
 *
 * Two things shape this:
 *
 *  - The map is NOT stored. 5000x5000 is 25 million tiles, all derived from
 *    WorldGen. Only *mutations* get rows, and only when they happen -- so
 *    `tile_states` holds depletion timers for the handful of tiles anyone has
 *    actually worked, and slot occupancy is derived by counting active jobs.
 *
 *  - Timestamps are unix milliseconds in plain integers, not datetimes. Every
 *    timer in the game is a millisecond deadline compared against the server
 *    clock, and round-tripping those through DB datetime types invites timezone
 *    and precision bugs in exactly the place the design can least afford them.
 *
 * Portable across SQLite and MySQL: no driver-specific column types, so the
 * database choice stays a .env decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §7 -- one character per wallet, soulbound. The wallet is the identity;
        // `session_id` is the development stand-in until wallet connect exists.
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('wallet')->unique();
            $table->string('session_id')->nullable()->index();
            // §2 -- a wallet must hold a minimum balance for 7 continuous days
            // before it can act. Recorded now, enforced when wallets are real.
            $table->unsignedBigInteger('eligible_since')->nullable();
            $table->timestamps();
        });

        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedInteger('ap')->default(0);
            $table->unsignedBigInteger('ap_updated_at');
            $table->unsignedBigInteger('gold')->default(0);
            $table->unsignedInteger('col');
            $table->unsignedInteger('row');
            // §6.2 -- presence is a session flag, nothing more.
            $table->string('presence_settlement_id')->nullable();
            $table->integer('tutorial_step')->default(0);
            $table->unsignedBigInteger('last_decay_at');
            $table->timestamps();

            $table->index(['col', 'row']);
        });

        // §3.1 -- resources are non-tradeable between players, so there is no
        // transfer table anywhere in this schema. That absence is the design.
        Schema::create('character_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('material_key');
            $table->unsignedInteger('quantity')->default(0);

            $table->unique(['character_id', 'material_key']);
        });

        Schema::create('character_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('skill_key');
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('xp')->default(0);

            $table->unique(['character_id', 'skill_key']);
        });

        Schema::create('character_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('item_key');
            // §8.2 -- at 0 an item is broken and inactive, never destroyed.
            $table->unsignedInteger('durability');
            $table->boolean('equipped')->default(false);
            $table->timestamps();

            $table->index(['character_id', 'equipped']);
        });

        Schema::create('jobs_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('kind');                 // mining | processing
            $table->string('status')->default('active');

            // mining
            $table->integer('col')->nullable();
            $table->integer('row')->nullable();
            $table->unsignedTinyInteger('slot')->nullable();
            $table->string('material_key')->nullable();

            // processing
            $table->string('settlement_id')->nullable();
            $table->string('recipe_key')->nullable();
            $table->string('output_key')->nullable();
            $table->boolean('presence')->default(false);

            $table->unsignedInteger('quantity');
            $table->string('skill_key');
            $table->unsignedBigInteger('started_at');
            $table->unsignedBigInteger('ends_at');
            $table->timestamps();

            // §5.1 -- two slots per hex, shared by everyone. Occupancy is a
            // COUNT over this index rather than a second table, so contention is
            // genuinely global instead of per-player bookkeeping.
            $table->index(['col', 'row', 'status']);
            // §6.1 -- same idea for the five-slot public processing queue.
            $table->index(['settlement_id', 'status']);
            $table->index(['character_id', 'status']);
        });

        // The only persisted map state: which tiles are worked out and when they
        // come back (§5.1). Rows appear on first depletion, never on generation.
        Schema::create('tile_states', function (Blueprint $table) {
            $table->id();
            $table->integer('col');
            $table->integer('row');
            $table->unsignedBigInteger('regrows_at')->default(0);

            $table->unique(['col', 'row']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tile_states');
        Schema::dropIfExists('jobs_queue');
        Schema::dropIfExists('character_items');
        Schema::dropIfExists('character_skills');
        Schema::dropIfExists('character_materials');
        Schema::dropIfExists('characters');
        Schema::dropIfExists('players');
    }
};
