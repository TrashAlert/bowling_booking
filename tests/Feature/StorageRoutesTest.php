<?php

use Illuminate\Support\Facades\Storage;

test('stored files cannot be read or written through laravel\'s own storage address', function (string $method) {
    Storage::fake('local');
    Storage::disk('local')->put('branding/logo.png', 'a picture');

    $response = $this->call($method, '/storage/branding/logo.png', ['upload' => $method === 'PUT' ? '1' : null]);

    $response->assertNotFound();
    expect(Storage::disk('local')->get('branding/logo.png'))->toBe('a picture');
})->with(['read' => ['GET'], 'write' => ['PUT']]);
