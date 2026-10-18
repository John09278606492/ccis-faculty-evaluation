<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\Settings;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        Settings::shouldReceive('get')->once()->andReturn([
            'project' => 'C:\\classroom', 'php' => 'C:\\php\\php.exe', 'port' => 8088, 'autostart' => true,
        ]);
        $response = $this->get('/');

        $response->assertStatus(200)->assertSee('Your classroom server')->assertSee('Copy link');
    }
}
