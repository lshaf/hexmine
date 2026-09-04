<?php

declare(strict_types=1);

use App\Game\Skills;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §7.4 -- a skill you hold at a rank, instead of a set of nodes you own.
 *
 * `character_nodes` held one row per node, which was the right shape while a
 * node was a thing with its own name. It is not any more: thirteen of them were
 * "+2 straps" under thirteen names, so what a character actually holds is one
 * skill at rank thirteen.
 *
 * The rank is a COUNT, and that is what makes the ladder structural rather than
 * enforced: you cannot hold rank 3 without rank 2, because 3 includes 2. The
 * old table needed code to stop somebody buying a capstone first.
 *
 * Spent points are still derived and never stored -- SUM(rank) here, where it
 * was COUNT(*) there -- so there is still nothing for a counter to drift from.
 *
 * `character_skill_ranks` rather than `character_skills`, which is taken: that
 * one is §7.2's five gathering LEVELS, which are a different number entirely --
 * one is earned by working and the other is bought with a point (§7.4.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_skill_ranks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('skill_key');
            $table->unsignedInteger('rank')->default(0);
            $table->timestamps();

            // One holding per skill. The rank is the whole of what is stored,
            // so two rows for one skill would be two opinions about it.
            $table->unique(['character_id', 'skill_key']);
        });

        // Carry every bought node across as a rank. A character who owned five
        // strap nodes comes out holding Straps at rank five, which is the same
        // +10 they had yesterday -- the node table is unchanged, so nothing
        // they were getting moves.
        $ranks = [];
        foreach (DB::table('character_nodes')->get() as $row) {
            $skill = Skills::skillForNode($row->node_key);
            if ($skill === null) {
                continue;
            }
            $ranks["{$row->character_id}|{$skill}"] = ($ranks["{$row->character_id}|{$skill}"] ?? 0) + 1;
        }

        $now = now();
        foreach (array_chunk($ranks, 500, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $at => $rank) {
                [$characterId, $skill] = explode('|', $at, 2);
                $rows[] = [
                    'character_id' => (int) $characterId,
                    'skill_key' => $skill,
                    'rank' => $rank,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('character_skill_ranks')->insert($rows);
        }

        // `character_nodes` is deliberately left standing. It is the only record
        // of WHICH nodes somebody bought, and a migration that drops it makes
        // the rollback below a lie.
    }

    public function down(): void
    {
        Schema::dropIfExists('character_skill_ranks');
    }
};
