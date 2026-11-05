<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/routers')->assertRedirect('/login');
        $this->get('/subscriptions')->assertRedirect('/login');
    }

    public function test_login_screen_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_a_user_can_log_in_and_reach_the_dashboard(): void
    {
        $user = User::create([
            'name' => 'Operator',
            'email' => 'boss@fastlink.local',
            'password' => bcrypt('secret-pw'),
        ]);

        $this->post('/login', [
            'email' => 'boss@fastlink.local',
            'password' => 'secret-pw',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
    }

    public function test_bad_credentials_are_rejected(): void
    {
        User::create([
            'name' => 'Operator',
            'email' => 'boss@fastlink.local',
            'password' => bcrypt('secret-pw'),
        ]);

        $this->post('/login', [
            'email' => 'boss@fastlink.local',
            'password' => 'wrong',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_user_can_log_out(): void
    {
        $user = User::create([
            'name' => 'Operator',
            'email' => 'boss@fastlink.local',
            'password' => bcrypt('secret-pw'),
        ]);

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
