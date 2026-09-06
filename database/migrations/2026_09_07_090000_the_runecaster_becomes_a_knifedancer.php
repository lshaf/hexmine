<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §9.5.4 -- the focus family becomes a pair of daggers, and its job with it.
 *
 * A rename, and nothing else moved: every effect, cap, cooldown and level gate
 * is what it was. What makes it a migration rather than a find-and-replace is
 * that the job key and the skill keys are STORED -- a character's job level
 * sits under `runecaster` and every rank they bought sits under
 * `runecaster.something`. Left alone, those rows would go on existing under
 * keys the catalog no longer has: the levels would read zero, the ranks would
 * vanish from the sheet, and the points spent on them would be gone with no
 * way to notice.
 *
 * The three battle skills are renamed inside that prefix as well, because their
 * keys are the skill's own (§7.4.2: a holding is a prefix of its own ladder, so
 * the row IS the skill).
 */
return new class extends Migration
{
    /** The skills whose own key changed, not just their job prefix. */
    private const SKILLS = [
        'ember_bolt' => 'bleeding_cut',
        'chain_arc' => 'rising_flurry',
        'rune_of_binding' => 'hamstring',
    ];

    public function up(): void
    {
        $this->rename('runecaster', 'knifedancer', self::SKILLS);
    }

    public function down(): void
    {
        $this->rename('knifedancer', 'runecaster', array_flip(self::SKILLS));
    }

    /** @param  array<string,string>  $skills */
    private function rename(string $from, string $to, array $skills): void
    {
        DB::table('character_jobs')->where('job_key', $from)->update(['job_key' => $to]);

        // The prefix first, then the three whose own name changed. In that
        // order, so the second pass matches keys that already carry the new
        // job -- doing it the other way round would rewrite half of each key
        // and leave the other half behind.
        foreach (DB::table('character_skill_ranks')->where('skill_key', 'like', $from.'.%')->get() as $row) {
            DB::table('character_skill_ranks')
                ->where('id', $row->id)
                ->update(['skill_key' => $to.'.'.substr($row->skill_key, strlen($from) + 1)]);
        }

        foreach ($skills as $old => $new) {
            DB::table('character_skill_ranks')
                ->where('skill_key', $to.'.'.$old)
                ->update(['skill_key' => $to.'.'.$new]);
        }
    }
};
