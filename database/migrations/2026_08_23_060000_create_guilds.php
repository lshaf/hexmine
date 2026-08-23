<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §10.0 -- a guild, and the hall it was founded in.
 *
 * A guild is a PLACE before it is a roster, which is why the settlement is on
 * the row rather than derived: §8.0's legendary bench is this hall and nowhere
 * else, so "where is it" has to be a fact rather than a lookup through whoever
 * happens to be standing there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guilds', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // A short tag, shown wherever a name would not fit.
            $table->string('code', 5)->unique();
            $table->string('description', 500)->default('');

            // §10.0.3 -- exactly 1024 colours, base64 of 3072 raw RGB bytes.
            // No upload, no URL, no file: what can be in this column is bounded
            // by the column's own shape.
            $table->text('flag')->nullable();

            // §10.0 -- founded at a city or a capital, never a village.
            $table->string('settlement_id');
            $table->integer('col');
            $table->integer('row');

            $table->foreignId('founder_character_id')->constrained('characters');

            // §10.0.1 -- open guilds are listed and joinable; closed ones are
            // not listed at all. This flag IS the join flow.
            $table->boolean('recruiting')->default(true);

            // §10.4 -- members donate here and donations are non-retractable.
            // Nothing spends it yet; the column is where bidding will read from.
            $table->unsignedBigInteger('gold')->default(0);

            $table->timestamps();

            $table->index('settlement_id');
            $table->index('recruiting');
        });

        Schema::create('guild_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guild_id')->constrained()->cascadeOnDelete();
            // §10.0 -- one guild each. The unique index is the guarantee; no
            // code path can put a character in two even if a request arrives
            // twice.
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role')->default('member')->comment('§10.0.2 owner | officer | member');
            $table->unsignedBigInteger('joined_at');
            $table->timestamps();

            $table->index(['guild_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_members');
        Schema::dropIfExists('guilds');
    }
};
