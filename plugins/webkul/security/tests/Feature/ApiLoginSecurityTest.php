<?php

/**
 * POST admin/api/v1/login: throttled per email + IP, refuses deactivated
 * users with the same answer as a wrong password, and a token stops working
 * as soon as its user is deactivated.
 */

use Webkul\Security\Models\User;

const API_LOGIN_URL = '/admin/api/v1/login';

const API_LOGIN_ERROR = 'The provided credentials are incorrect.';

function apiLoginUser(bool $isActive = true): User
{
    return User::factory()->create(['is_active' => $isActive]);
}

it('issues a token to an active user with the right password', function () {
    $user = apiLoginUser();

    $this->postJson(API_LOGIN_URL, ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure(['token', 'token_type']);
});

it('refuses a deactivated user with the same error as a wrong password', function () {
    $inactive = apiLoginUser(isActive: false);
    $active = apiLoginUser();

    $inactiveResponse = $this->postJson(API_LOGIN_URL, ['email' => $inactive->email, 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', API_LOGIN_ERROR);

    $wrongPasswordResponse = $this->postJson(API_LOGIN_URL, ['email' => $active->email, 'password' => 'not-the-password'])
        ->assertUnprocessable();

    expect($inactiveResponse->json())->toBe($wrongPasswordResponse->json())
        ->and($inactive->tokens()->count())->toBe(0);
});

it('throttles login attempts after five per minute for the same email and IP', function () {
    $user = apiLoginUser();

    foreach (range(1, 5) as $attempt) {
        $this->postJson(API_LOGIN_URL, ['email' => $user->email, 'password' => 'wrong-'.$attempt])
            ->assertUnprocessable();
    }

    $this->postJson(API_LOGIN_URL, ['email' => $user->email, 'password' => 'password'])
        ->assertStatus(429);

    $this->postJson(API_LOGIN_URL, ['email' => strtoupper($user->email), 'password' => 'password'])
        ->assertStatus(429);

    $other = apiLoginUser();

    $this->postJson(API_LOGIN_URL, ['email' => $other->email, 'password' => 'password'])
        ->assertOk();
});

it('stops accepting the existing tokens of a user who is deactivated', function () {
    $user = apiLoginUser();
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withToken($token)->getJson('/admin/api/v1/devices')->assertOk();

    $user->forceFill(['is_active' => false])->saveQuietly();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/admin/api/v1/devices')->assertUnauthorized();
    $this->withToken($token)->postJson('/admin/api/v1/logout')->assertUnauthorized();
});

it('rejects a token held by a user who is already deactivated', function () {
    $user = apiLoginUser(isActive: false);
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withToken($token)->getJson('/admin/api/v1/devices')->assertUnauthorized();
});
