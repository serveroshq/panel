<?php

use App\Models\User;

beforeEach(function () {
    config()->set('serveros.agents', true);
});

test('starting with Agents signs up and lands on Agents, not connect a machine', function () {
    $this->get('/start/agents')->assertRedirect(route('register'));

    $this->post(route('register.store'), [
        'name' => 'Alex Rivera',
        'email' => 'alex@northwind.dev',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', ['to' => '/agents']));

    $user = User::firstWhere('email', 'alex@northwind.dev');
    $user->markEmailAsVerified();
    $workspace = $user->defaultWorkspace();
    $this->actingAs($user->fresh())->get(route('dashboard', ['to' => '/agents']))
        ->assertRedirect(rtrim($workspace->url(), '/').'/agents');
});

test('signed in, starting with Agents goes straight there', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/start/agents')->assertRedirect(route('dashboard', ['to' => '/agents']));
});

test('there is no Agents to start with while it is off', function () {
    config()->set('serveros.agents', false);

    $this->get('/start/agents')->assertNotFound();
});
