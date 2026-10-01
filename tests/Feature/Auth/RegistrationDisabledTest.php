<?php

test('the registration screen is not available', function () {
    $response = $this->get('/register');

    $response->assertNotFound();
});

test('new users cannot register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
    $this->assertDatabaseCount('users', 0);
});
