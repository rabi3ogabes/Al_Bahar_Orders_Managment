<?php

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Exceptions\OtpException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\FreshTwoFactor;
use App\Domain\Identity\Services\OtpService;
use App\Domain\Identity\Services\OtpTestMode;
use App\Domain\Identity\Services\TwoFactorService;
use App\Domain\Notifications\Channels\ConfiguredSmsSender;
use App\Domain\Notifications\Contracts\SmsSender;
use App\Domain\Notifications\Models\MessageProviderEvent;
use App\Domain\Notifications\Providers\InfobipPreset;
use App\Domain\Notifications\Providers\MessagingGateway;
use App\Domain\Notifications\Providers\MessagingProviders;
use App\Domain\Notifications\Providers\MessagingTester;
use App\Domain\Notifications\Providers\TwilioPreset;
use App\Domain\Notifications\Providers\UnifonicPreset;
use App\Domain\Notifications\Services\WhatsAppClient;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Organization\MessagingProvidersPage;
use App\Support\DomainException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['platform.otp.dev_echo' => false, 'platform.otp.test_code' => '', 'services.sms.driver' => 'null']);
});

// A made-up account id in Twilio's shape (AC + 32 hex), built at run time so secret scanners do not mistake the test for a leaked key.
define('TWILIO_SID', 'AC'.str_repeat('0123456789abcdef', 2));

/** Saves a provider the way the dashboard does (sealed secrets). */
function connectProvider(string $sms = 'twilio', string $whatsapp = 'none'): void
{
    $s = app(Settings::class);
    $fields = ['twilio' => ['account_sid' => TWILIO_SID, 'auth_token' => 'tok-0123456789abcdef-secret', 'sms_from' => '+15005550006', 'whatsapp_from' => '+14155238886'],
        'unifonic' => ['app_sid' => 'app-sid-0123456789abcdef', 'sender_id' => 'ALBAHAR'],
        'infobip' => ['base_url' => 'https://abc12.api.infobip.com', 'api_key' => 'ib-key-0123456789abcdef', 'sender' => 'ALBAHAR'],
        'http' => ['url' => 'https://sms.example.com/send', 'auth_header' => 'X-Api-Key', 'auth_value' => 'gw-key-0123', 'sender' => 'ALBAHAR']];
    foreach ($fields[$sms === 'none' ? $whatsapp : $sms] ?? [] as $k => $v) {
        $s->set(MessagingProviders::settingKey($sms === 'none' ? $whatsapp : $sms, $k), $v, secret: in_array($k, ['auth_token', 'app_sid', 'api_key', 'auth_value'], true));
    }
    $s->set(MessagingProviders::SMS, $sms);
    $s->set(MessagingProviders::WHATSAPP, $whatsapp);
    app(MessagingProviders::class)->touch();
}

function ownerWithFreshCode(): User
{
    $owner = User::factory()->superAdmin()->create();
    $owner->forceFill(['two_factor_secret' => app(TwoFactorService::class)->generateSecret(), 'two_factor_confirmed_at' => now()])->save();
    test()->actingAs($owner);
    session(['two_factor_passed' => true]);
    app(FreshTwoFactor::class)->stamp();

    return $owner;
}

// ─── The exact request each preset sends ─────────────────────────────────────────────────────────────────────────────────────────

it('builds the exact Twilio request for SMS, for a messaging service, and for WhatsApp', function () {
    $c = ['account_sid' => TWILIO_SID, 'auth_token' => 'tok', 'sms_from' => '+15005550006', 'whatsapp_from' => '+14155238886'];
    $sms = (new TwilioPreset)->request($c, '+97455123456', 'Hello', 'sms');
    expect($sms)->toBe(['method' => 'POST', 'url' => 'https://api.twilio.com/2010-04-01/Accounts/'.TWILIO_SID.'/Messages.json', 'headers' => ['Accept' => 'application/json'],
        'auth' => [TWILIO_SID, 'tok'], 'type' => 'form', 'body' => ['To' => '+97455123456', 'From' => '+15005550006', 'Body' => 'Hello']]);
    expect((new TwilioPreset)->request($c, '+97455123456', 'Hello', 'whatsapp')['body'])->toBe(['To' => 'whatsapp:+97455123456', 'From' => 'whatsapp:+14155238886', 'Body' => 'Hello']);
    expect((new TwilioPreset)->request(['sms_from' => 'MGabc'] + $c, '+97455123456', 'Hi', 'sms')['body'])->toBe(['To' => '+97455123456', 'MessagingServiceSid' => 'MGabc', 'Body' => 'Hi']);
});

it('builds the exact Unifonic and Infobip requests', function () {
    $u = (new UnifonicPreset)->request(['app_sid' => 'sid', 'sender_id' => 'ALBAHAR'], '+97455123456', 'Hello', 'sms');
    expect($u)->toBe(['method' => 'POST', 'url' => 'https://api.unifonic.com/rest/SMS/messages', 'headers' => ['Accept' => 'application/json'], 'auth' => null, 'type' => 'form',
        'body' => ['AppSid' => 'sid', 'SenderID' => 'ALBAHAR', 'Body' => 'Hello', 'Recipient' => '97455123456', 'responseType' => 'JSON']]);
    $i = (new InfobipPreset)->request(['base_url' => 'abc12.api.infobip.com/', 'api_key' => 'key', 'sender' => 'ALBAHAR'], '+97455123456', 'Hello', 'sms');
    expect($i)->toBe(['method' => 'POST', 'url' => 'https://abc12.api.infobip.com/sms/2/text/advanced', 'headers' => ['Authorization' => 'App key', 'Accept' => 'application/json'], 'auth' => null, 'type' => 'json',
        'body' => ['messages' => [['from' => 'ALBAHAR', 'destinations' => [['to' => '97455123456']], 'text' => 'Hello']]]]);
});

it('reads the answers of the providers, including their refusals', function () {
    $t = new TwilioPreset;
    expect($t->parse(201, ['sid' => 'SM1', 'status' => 'queued'], []))->toBe(['ok' => true, 'status' => 'queued', 'id' => 'SM1', 'code' => null, 'error' => null])
        ->and($t->parse(400, ['code' => 21211, 'message' => 'Invalid To number'], [])['code'])->toBe('21211')
        ->and($t->parseStatus(200, ['status' => 'delivered']))->toBe('delivered')->and($t->parseStatus(200, ['status' => 'undelivered']))->toBe('failed');
    expect((new UnifonicPreset)->parse(200, ['success' => true, 'data' => ['MessageID' => '77', 'Status' => 'Queued']], [])['id'])->toBe('77')
        ->and((new UnifonicPreset)->parse(200, ['success' => false, 'errorCode' => 'ER-02', 'message' => 'Bad sender'], [])['code'])->toBe('ER-02');
    expect((new InfobipPreset)->parse(200, ['messages' => [['messageId' => 'ib1', 'status' => ['groupName' => 'PENDING']]]], [])['id'])->toBe('ib1')
        ->and((new InfobipPreset)->parse(200, ['messages' => [['messageId' => 'ib2', 'status' => ['groupName' => 'REJECTED', 'name' => 'REJECTED_NOT_ENOUGH_CREDITS', 'description' => 'No credit']]]], [])['ok'])->toBeFalse()
        ->and((new InfobipPreset)->parseStatus(200, ['results' => [['status' => ['groupName' => 'DELIVERED']]]]))->toBe('delivered');
});

// ─── Sending: timeout, one retry, no secrets ─────────────────────────────────────────────────────────────────────────────────────

it('sends through Twilio with basic auth, retries ONCE on a server error, and never retries a refusal', function () {
    connectProvider('twilio');
    Http::fakeSequence('api.twilio.com/*')->push(['message' => 'Service unavailable'], 503)->push(['sid' => 'SM1', 'status' => 'queued'], 201)->push(['code' => 21211, 'message' => 'Invalid To number'], 400);
    $r = app(MessagingGateway::class)->send('sms', '+97455123456', 'Hello');
    expect($r['ok'])->toBeTrue()->and($r['id'])->toBe('SM1');
    Http::assertSentCount(2); // the 503, then the retry
    Http::assertSent(fn ($req) => $req->hasHeader('Authorization') && str_starts_with($req->header('Authorization')[0], 'Basic ') && $req['To'] === '+97455123456');

    $r = app(MessagingGateway::class)->send('sms', '+97455123456', 'Hello');
    expect($r['ok'])->toBeFalse()->and($r['code'])->toBe('21211');
    Http::assertSentCount(3); // the refusal was tried once: no retry
});

it('turns a dead provider into a plain failure, without raising', function () {
    connectProvider('infobip');
    Http::fake(fn () => throw new ConnectionException('timeout'));
    $r = app(MessagingGateway::class)->send('sms', '+97455123456', 'Hello');
    expect($r['ok'])->toBeFalse()->and($r['code'])->toBe('connection');
});

it('refuses a gateway address that is not a public https address', function () {
    expect(MessagingGateway::safeUrl('https://sms.example.com/send'))->toBeTrue()->and(MessagingGateway::safeUrl('http://sms.example.com'))->toBeFalse()
        ->and(MessagingGateway::safeUrl('https://localhost/x'))->toBeFalse()->and(MessagingGateway::safeUrl('https://10.0.0.5/x'))->toBeFalse()
        ->and(MessagingGateway::safeUrl('https://169.254.169.254/latest'))->toBeFalse()->and(MessagingGateway::safeUrl('https://8.8.8.8/x'))->toBeTrue();
});

it('sends sign-in codes through the provider, records a failure without the code, and keeps secrets out of logs and rows', function () {
    connectProvider('twilio');
    Log::spy();
    Http::fake(['api.twilio.com/*' => Http::response(['code' => 30008, 'message' => 'Unknown error for code 482913'], 500)]);
    expect(app(SmsSender::class))->toBeInstanceOf(ConfiguredSmsSender::class);

    $code = null;
    try {
        app(OtpService::class)->issue('+97455123456', OtpPurpose::Login);
    } catch (DomainException $e) {
        $code = $e->errorCode;
    }
    expect($code)->toBe('sms_failed');
    $row = MessageProviderEvent::firstOrFail();
    expect($row->purpose)->toBe('otp')->and($row->provider)->toBe('twilio')->and($row->status)->toBe('failed')->and($row->error_code)->toBe('30008')
        ->and($row->recipient)->not->toContain('55123456');
    $dump = json_encode(DB::table('message_provider_events')->get()).json_encode(DB::table('audit_logs')->get());
    expect($dump)->not->toContain('482913')->not->toContain('tok-0123456789abcdef-secret');
    Log::shouldNotHaveReceived('info', fn ($m, $ctx = []) => str_contains(json_encode($ctx), 'tok-0123456789abcdef-secret'));
});

it('sends a sign-in code through the provider once, with the code only in the request', function () {
    connectProvider('unifonic');
    Http::fake(['api.unifonic.com/*' => Http::response(['success' => true, 'data' => ['MessageID' => '9', 'Status' => 'Queued']], 200)]);
    $out = app(OtpService::class)->issue('+97455123456', OtpPurpose::Login);
    Http::assertSentCount(1);
    Http::assertSent(fn ($req) => $req['Recipient'] === '97455123456' && preg_match('/\d{6}/', $req['Body']) === 1);
    expect($out['debug_code'])->toBeNull()->and(MessageProviderEvent::count())->toBe(0);
});

it('keeps today\'s behaviour when no provider is set: the server driver, no web call', function () {
    Http::fake(['legacy.example.com/*' => Http::response(['id' => 'L1'], 200)]);
    app(SmsSender::class)->send('+97455123456', 'Hello', 'otp');
    Http::assertNothingSent();
    expect(app(SmsSender::class)->name())->toBe('null');
    config(['services.sms.driver' => 'http', 'services.sms.http.url' => 'https://legacy.example.com/send', 'services.sms.http.auth_header' => 'Authorization', 'services.sms.http.auth_value' => 'x',
        'services.sms.http.fields' => ['to' => 'to', 'message' => 'message', 'sender' => 'sender']]);
    expect(app(SmsSender::class)->send('+97455123456', 'Hello', 'otp')['provider_message_id'])->toBe('L1');
});

it('sends WhatsApp through Twilio with the whatsapp: prefixes, and shows as connected', function () {
    connectProvider('none', 'twilio');
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'WA1', 'status' => 'queued'], 201)]);
    $client = app(WhatsAppClient::class);
    expect($client->configured())->toBeTrue()->and($client->sendText('+97455123456', 'Hello'))->toBe(['id' => 'WA1']);
    Http::assertSent(fn ($r) => $r['To'] === 'whatsapp:+97455123456' && $r['From'] === 'whatsapp:+14155238886');
});

// ─── The dashboard page ────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('saves a provider sealed, never sends a secret back to the page, and rejects a bad format', function () {
    $this->actingAs(staffWithRole('admin'));
    $c = Livewire::test(MessagingProvidersPage::class)->set('smsProvider', 'twilio')
        ->set('fields.twilio.account_sid', 'nope')->call('save')->assertHasErrors(['fields.twilio.account_sid', 'fields.twilio.auth_token', 'fields.twilio.sms_from']);
    $c->set('fields.twilio.account_sid', TWILIO_SID)->set('fields.twilio.auth_token', 'tok-0123456789abcdef-secret')->set('fields.twilio.sms_from', '+15005550006')->call('save')->assertHasNoErrors();
    expect($c->get('fields')['twilio']['auth_token'])->toBe('');
    $row = DB::table('system_settings')->where('group', 'messaging')->where('key', 'twilio_auth_token')->first();
    expect($row->is_secret)->toBeTrue()->and($row->value)->not->toContain('tok-0123456789abcdef-secret')
        ->and(app(MessagingProviders::class)->configured('sms'))->toBeTrue();
    $c->assertDontSee('tok-0123456789abcdef-secret')->assertSee(__('messaging_providers.secret_saved'), false);
    expect(DB::table('audit_logs')->where('action', 'settings.updated')->get()->pluck('new_values')->join(' '))->not->toContain('tok-0123456789abcdef-secret');
});

it('refuses a gateway address that is not https, and shows nothing connected before saving', function () {
    $this->actingAs(staffWithRole('admin'));
    Livewire::test(MessagingProvidersPage::class)->assertSee(__('messaging_providers.not_connected'))
        ->set('smsProvider', 'http')->set('fields.http.url', 'http://insecure.example.com')->set('fields.http.auth_header', 'Authorization')->set('fields.http.auth_value', 'abcd1234')->call('save')->assertHasErrors('fields.http.url');
});

it('sends a real test message, shows the answer, and lets an admin mark it received', function () {
    connectProvider('twilio');
    $this->actingAs(staffWithRole('admin'));
    Http::fake(['api.twilio.com/*/Messages.json' => Http::response(['sid' => 'SM9', 'status' => 'queued'], 201), 'api.twilio.com/*/Messages/SM9.json' => Http::response(['status' => 'sent'], 200)]);
    $c = Livewire::test(MessagingProvidersPage::class)->set('testNumber', '123')->call('sendTest')->assertHasErrors('testNumber')
        ->set('testNumber', '5512 3456')->call('sendTest')->assertHasNoErrors();
    $e = MessageProviderEvent::firstOrFail();
    expect($e->purpose)->toBe('test')->and($e->status)->toBe('sent')->and($e->provider_message_id)->toBe('SM9')->and($e->recipient)->not->toContain('55123456')->and($e->confirmed_at)->toBeNull();
    $c->assertSee(__('messaging_providers.s.sent'))->call('confirmReceived', $e->id);
    expect($e->fresh()->confirmed_at)->not->toBeNull()->and($e->fresh()->status)->toBe('delivered');
    expect(DB::table('audit_logs')->whereIn('action', ['messaging.test_sent', 'messaging.test_confirmed'])->count())->toBe(2);
});

it('shows the provider\'s refusal and does not let a refused test be confirmed', function () {
    connectProvider('twilio');
    $this->actingAs(staffWithRole('admin'));
    Http::fake(['api.twilio.com/*' => Http::response(['code' => 21608, 'message' => 'Number not verified'], 400)]);
    $c = Livewire::test(MessagingProvidersPage::class)->set('testNumber', '55123456')->call('sendTest');
    $e = MessageProviderEvent::firstOrFail();
    expect($e->status)->toBe('failed')->and($e->error_code)->toBe('21608');
    $c->assertSee('21608')->assertSee('Number not verified')->call('confirmReceived', $e->id)->assertDispatched('toast', type: 'error');
    expect($e->fresh()->confirmed_at)->toBeNull();
});

it('rate-limits test messages and refuses a channel with no provider', function () {
    $this->actingAs(staffWithRole('admin'));
    Livewire::test(MessagingProvidersPage::class)->set('testNumber', '55123456')->call('sendTest')->assertHasErrors('testNumber');
    connectProvider('twilio');
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'S', 'status' => 'queued'], 201)]);
    $c = Livewire::test(MessagingProvidersPage::class)->set('testNumber', '55123456');
    foreach (range(1, 5) as $i) {
        $c->call('sendTest');
    }
    $c->call('sendTest')->assertDispatched('toast', type: 'error');
    expect(MessageProviderEvent::count())->toBe(5);
});

it('lets people with only the view right look, but not change, test or switch anything', function () {
    $this->actingAs(staffWithRole('auditor'));
    Livewire::test(MessagingProvidersPage::class)->assertOk()->call('save')->assertForbidden();
    Livewire::test(MessagingProvidersPage::class)->call('sendTest')->assertForbidden();
    $this->actingAs(staffWithRole('customer_support'));
    Livewire::test(MessagingProvidersPage::class)->assertForbidden();
});

it('renders in Arabic', function () {
    $this->actingAs(staffWithRole('admin'));
    app()->setLocale('ar');
    Livewire::test(MessagingProvidersPage::class)->assertSee('الرسائل النصية وواتساب')->assertSee('غير متصل');
});

// ─── The switch for the test sign-in codes ────────────────────────────────────────────────────────────────────────────────────────

it('keeps today\'s sign-in exactly as it is until the owner acts: the fixed code works, even with a provider typed in', function () {
    config(['platform.otp.test_code' => '123456']);
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '55123456', 'purpose' => 'login'])->assertOk()->assertJsonPath('data.debug_code', '123456');
    connectProvider('twilio');
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'S', 'status' => 'queued'], 201)]);
    expect(app(OtpTestMode::class)->active())->toBeTrue();
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '55123457', 'purpose' => 'login'])->assertOk()->assertJsonPath('data.debug_code', '123456');
});

it('refuses to turn the test codes off before a delivered test, without the owner, and without a fresh code', function () {
    config(['platform.otp.test_code' => '123456']);
    $owner = ownerWithFreshCode();
    $mode = app(OtpTestMode::class);

    expect($mode->canTurnOff())->toBeFalse();
    expect(fn () => $mode->turnOff($owner))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('test_codes_need_proof'));

    connectProvider('twilio');
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'delivered'], 201)]);
    $c = Livewire::test(MessagingProvidersPage::class)->set('testNumber', '55123456')->call('sendTest');
    expect($mode->canTurnOff())->toBeFalse('sent is not received: an admin must press "I got it"');
    $c->call('confirmReceived', MessageProviderEvent::firstOrFail()->id);
    expect($mode->providerProven())->toBeTrue()->and($mode->canTurnOff())->toBeTrue();

    $admin = staffWithRole('admin');
    expect(fn () => $mode->turnOff($admin))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('security_owner_only'));
    session(['two_factor_confirmed_at' => time() - 400]);
    expect(fn () => $mode->turnOff($owner))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('security_two_factor_required'));
    expect($mode->active())->toBeTrue();
});

it('ignores OTP_TEST_CODE after the owner turns it off, even though the environment still has it, and can bring it back', function () {
    config(['platform.otp.test_code' => '123456']);
    $owner = ownerWithFreshCode();
    connectProvider('twilio');
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);
    $e = app(MessagingTester::class)->send('sms', '+97455123456', $owner);
    app(MessagingTester::class)->confirmReceived($e, $owner);

    app(OtpTestMode::class)->turnOff($owner);
    expect(app(OtpTestMode::class)->active())->toBeFalse()->and(config('platform.otp.test_code'))->toBe('123456');
    $out = app(OtpService::class)->issue('+97455123999', OtpPurpose::Login);
    expect($out['debug_code'])->toBeNull();
    expect(fn () => app(OtpService::class)->verify('+97455123999', OtpPurpose::Login, '123456'))->toThrow(OtpException::class);
    $audit = DB::table('audit_logs')->where('action', 'security.test_codes_off')->first();
    expect($audit)->not->toBeNull()->and($audit->actor_id)->toBe($owner->id);

    app(OtpTestMode::class)->turnOn($owner);
    expect(app(OtpTestMode::class)->active())->toBeTrue();
});

it('changes a saved provider back to unproven: a test from before the change does not count', function () {
    config(['platform.otp.test_code' => '123456']);
    $owner = ownerWithFreshCode();
    connectProvider('twilio');
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);
    $tester = app(MessagingTester::class);
    $tester->confirmReceived($tester->send('sms', '+97455123456', $owner), $owner);
    expect(app(OtpTestMode::class)->providerProven())->toBeTrue();
    $this->travel(5)->seconds();
    app(MessagingProviders::class)->touch();
    expect(app(OtpTestMode::class)->providerProven())->toBeFalse();
});

it('shows the red warning on the home page to the owner only, and drops it once the codes are off', function () {
    config(['platform.otp.test_code' => '123456']);
    $this->actingAs(User::factory()->superAdmin()->create());
    Livewire::test(Dashboard::class)->assertSee(__('messaging_providers.home_warning'));
    $this->actingAs(staffWithRole('admin'));
    Livewire::test(Dashboard::class)->assertDontSee(__('messaging_providers.home_warning'));
    app(Settings::class)->set(OtpTestMode::OFF, true);
    $this->actingAs(User::factory()->superAdmin()->create());
    Livewire::test(Dashboard::class)->assertDontSee(__('messaging_providers.home_warning'));
});

it('walks the turn-off window on the page with a two-factor code', function () {
    config(['platform.otp.test_code' => '123456']);
    $owner = ownerWithFreshCode();
    connectProvider('twilio');
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);
    $tester = app(MessagingTester::class);
    $tester->confirmReceived($tester->send('sms', '+97455123456', $owner), $owner);
    session(['two_factor_confirmed_at' => time() - 400]);

    $c = Livewire::test(MessagingProvidersPage::class)->assertSee(__('messaging_providers.codes_on_warning'))->call('askCode', 'off')->assertSet('showCode', true)
        ->set('code', '000000')->call('switchTestCodes')->assertHasErrors('code');
    expect(app(OtpTestMode::class)->active())->toBeTrue();
    RateLimiter::clear('2fa-fresh:'.$owner->id);
    $secret = $owner->fresh()->two_factor_secret;
    $c->set('code', app(TwoFactorService::class)->totp($secret, intdiv(time(), 30)))->call('switchTestCodes')->assertHasNoErrors();
    expect(app(OtpTestMode::class)->active())->toBeFalse();
});
