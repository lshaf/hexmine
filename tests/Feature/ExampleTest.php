<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    /**
     * The almanac is a page of its own, not the SPA shell. It is registered
     * ahead of the catch-all, and this is what catches someone moving it back
     * below -- the catch-all would answer with a 200 either way, so the test
     * has to look at which page came back.
     */
    public function test_the_almanac_is_served_as_its_own_page(): void
    {
        $response = $this->get('/almanac');

        $response->assertStatus(200);
        $response->assertSee('<title>Almanac', false);
    }
}
