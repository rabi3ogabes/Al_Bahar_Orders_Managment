<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Services\FirebaseConfig;
use App\Domain\Notifications\Services\PushTester;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** The dashboard's "Send a test to a phone", from the command line: a real push to one person's registered phones, with Firebase's answer in plain words. */
class PushTest extends Command
{
    protected $signature = 'push:test {user? : the person: id, phone number or e-mail (default: the phone that registered last)} {--card : also send the sample live order card to a customer app on Android}';

    protected $description = 'Send a real test notification to a registered phone and say in plain words what Firebase answered.';

    public function handle(PushTester $tester, FirebaseConfig $config): int
    {
        if (! $config->configured()) {
            $this->error(__('push_health.fcm.not_ready', [], 'en'));

            return self::FAILURE;
        }
        $devices = $this->devices();
        if ($devices === null) {
            return self::FAILURE;
        }
        if ($devices->isEmpty()) {
            $this->warn(__('push_health.test.no_devices', [], 'en'));

            return self::FAILURE;
        }
        $ok = 0;
        $lastError = null;
        foreach ($devices as $device) {
            $r = $tester->send($device, (bool) $this->option('card'));
            $ok += $r['ok'] ? 1 : 0;
            $lastError = $r['ok'] ? $lastError : trim('HTTP '.$r['status'].' '.($r['code'] ?? ''));
            $this->line(($r['ok'] ? '[ok]     ' : '[failed] ').PushTester::label($device));
            $this->line('         '.__('push_health.fcm.'.$r['explain'], [], 'en'));
            if ($r['status'] || $r['code']) {
                $this->line('         '.__('push_health.test.technical', ['status' => $r['status'], 'code' => $r['code'] ?? ''], 'en'));
            }
            if ($r['card'] !== null) {
                $this->line('         '.($r['card']['ok'] ? __('push_health.test.card_ok', [], 'en') : __('push_health.test.card_failed', ['status' => $r['card']['status'], 'code' => $r['card']['code'] ?? ''], 'en')));
            }
        }
        $tester->log($devices->first()->user_id, $devices->count(), $ok, $lastError);
        $this->info(__('push_health.test.sent_n', ['ok' => $ok, 'total' => $devices->count()], 'en'));

        return $ok > 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return Collection<int, UserDevice>|null null after saying why the person cannot be found */
    private function devices(): ?Collection
    {
        $who = $this->argument('user');
        $query = UserDevice::with('user:id,name,phone')->whereNull('disabled_at')->orderByDesc('last_seen_at');
        if ($who === null) {
            return $query->limit(1)->get();
        }
        $who = (string) $who;
        $user = Str::isUuid($who) ? User::find($who) : User::where('email', mb_strtolower($who))->orWhere('phone', Phone::normalize($who) ?? '-')->first();
        if ($user === null) {
            $this->error("No person found for \"{$who}\" (use the id, the phone number or the e-mail).");

            return null;
        }

        return $query->where('user_id', $user->id)->limit(10)->get();
    }
}
