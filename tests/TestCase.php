<?php

namespace Tests;

use App\Game\Balance;
use App\Game\WorldGen;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suite runs on Balance::FIXTURE_MAP_RADIUS, never on the deployment's
     * own map size.
     *
     * Generation is scale-relative -- ring boundaries are fractions of the
     * radius and every lattice is an absolute hex count -- so a small map runs
     * the same code. What it saves is the clock: several tests here sweep every
     * hex on a stride, and on the shipping map that is tens of millions of
     * tiles apiece. It is also what keeps tests/Fixtures/worldgen.txt meaningful,
     * since the fixture is frozen at the same radius.
     *
     * A test that wants a different map sets it itself and calls
     * WorldGen::forget(); this only installs the default.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['game.map.radius' => Balance::FIXTURE_MAP_RADIUS]);
        WorldGen::forget();
    }
}
