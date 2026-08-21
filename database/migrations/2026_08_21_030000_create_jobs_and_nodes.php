<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §7.4 -- jobs and the tree nodes bought against them.
 *
 * Two tables because they are two different things (§7.4.1): a job level is
 * earned by working and only ever gates, while a node is bought with a skill
 * point and is what actually does something. Keeping them apart is what stops
 * one being mistaken for the other.
 *
 * There is no `spent_points` column anywhere. Spent points are `count(*)` of
 * this character's nodes, so the two can never disagree — a stored counter
 * would be a second opinion about the same fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('job_key');
            $table->unsignedInteger('level')->default(1);
            $table->unsignedBigInteger('xp')->default(0);
            $table->timestamps();

            $table->unique(['character_id', 'job_key']);
        });

        Schema::create('character_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('node_key');
            $table->timestamps();

            // §7.4.2 -- nodes are bought, never refunded, and never bought
            // twice. The unique index is the guarantee; no code path can
            // double-spend a point even if a request arrives twice.
            $table->unique(['character_id', 'node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_nodes');
        Schema::dropIfExists('character_jobs');
    }
};
