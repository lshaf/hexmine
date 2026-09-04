<?php

declare(strict_types=1);

use App\Game\Jobs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §9.5.4 -- attack and defense are two solid numbers, so they are two skills.
 *
 * The first collapse put every `pair` node of a job into one skill, which made
 * a Shieldbearer at rank 5 an unknown blend of the two -- and those are the
 * numbers a fight is decided by (§9.5.5). They split on the stat now, so a
 * `<job>.pair` holding written by that migration names a skill that no longer
 * exists.
 *
 * Which ranks somebody actually held is recoverable exactly, because a rank was
 * never a choice: the old ladder was the job's pair nodes ordered by level, and
 * holding rank N meant holding the first N of them. So the split is a matter of
 * replaying that order and counting how many of the first N were attack and how
 * many were defense. Nobody gains or loses a point.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('character_skill_ranks')->where('skill_key', 'like', '%.pair')->get() as $row) {
            $job = substr($row->skill_key, 0, -strlen('.pair'));

            // The old merged ladder, rebuilt the way the generator built it:
            // file order, then a stable sort by the level that opened each one.
            $ladder = [];
            foreach (Jobs::NODES as $node) {
                if ($node['job'] === $job && $node['effect']['kind'] === 'pair') {
                    $ladder[] = $node;
                }
            }
            usort($ladder, static fn (array $a, array $b) => $a['jobLevel'] <=> $b['jobLevel']);

            $held = ['attack' => 0, 'defense' => 0];
            foreach (array_slice($ladder, 0, (int) $row->rank) as $node) {
                $held[$node['effect']['stat']]++;
            }

            foreach ($held as $stat => $rank) {
                if ($rank > 0) {
                    DB::table('character_skill_ranks')->insert([
                        'character_id' => $row->character_id,
                        'skill_key' => "{$job}.pair:{$stat}",
                        'rank' => $rank,
                        'created_at' => $row->created_at,
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('character_skill_ranks')->where('id', $row->id)->delete();
        }
    }

    public function down(): void
    {
        // Merging them back would have to guess an order the split threw away,
        // and the ranks are the same points either way.
    }
};
