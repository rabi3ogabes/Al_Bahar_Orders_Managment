<?php

it('tells the browser how to reach the live socket without exposing any secret', function () {
    config(['reverb.apps.apps.0.key' => 'albahar-key', 'reverb.apps.apps.0.secret' => 'top-secret']);
    $r = $this->getJson('/api/v1/realtime-config')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.key', 'albahar-key');
    expect($r->getContent())->not->toContain('top-secret');
    expect($r->json('data.auth_endpoint'))->toEndWith('/api/v1/broadcasting/auth');
});

it('reports live updates as off when no key is configured', function () {
    config(['reverb.apps.apps.0.key' => null]);
    $this->getJson('/api/v1/realtime-config')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.key', null);
});
