<?php

use App\Domain\Audit\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('reports health of its dependencies', function () {
    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('checks.database', 'ok');
});

it('assigns and echoes a correlation id', function () {
    $this->getJson('/api/v1/health', ['X-Request-ID' => 'req-12345678'])->assertHeader('X-Request-ID', 'req-12345678');
    expect($this->getJson('/api/v1/health')->headers->get('X-Request-ID'))->toMatch('/^[0-9a-f-]{36}$/');
});

it('selects the API locale from Accept-Language and ?lang', function () {
    $this->getJson('/api/v1/me', ['Accept-Language' => 'ar'])->assertStatus(401)
        ->assertJsonPath('message', 'يرجى تسجيل الدخول للمتابعة.')->assertHeader('Content-Language', 'ar');
    $this->getJson('/api/v1/me?lang=en', ['Accept-Language' => 'ar'])->assertJsonPath('message', 'Please sign in to continue.');
});

it('returns a uniform 404 envelope without internals', function () {
    $this->getJson('/api/v1/does-not-exist')->assertStatus(404)
        ->assertJsonPath('code', 'not_found')->assertJsonMissingPath('exception')->assertJsonMissingPath('trace');
});

it('redacts secrets in audit logs and keeps audit logs immutable', function () {
    $log = app(AuditLogger::class)->log('settings.updated', 'Setting', ['api_key' => 'abc'], ['password' => 'x', 'name' => 'ok', 'phone' => '+97455123456']);

    expect($log->old_values['api_key'])->toBe('[REDACTED]')
        ->and($log->new_values['password'])->toBe('[REDACTED]')
        ->and($log->new_values['name'])->toBe('ok')
        ->and($log->new_values['phone'])->not->toBe('+97455123456');

    expect(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']))->toThrow(QueryException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
});
