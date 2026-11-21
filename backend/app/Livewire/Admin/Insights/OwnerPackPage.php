<?php

namespace App\Livewire\Admin\Insights;

use App\Domain\Reports\Models\OwnerPackSend;
use App\Domain\Reports\Services\OwnerPackData;
use App\Domain\Reports\Services\OwnerPackSender;
use App\Domain\Reports\Services\OwnerPackSettings;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Insights > Owner pack. The question of the page is "What will the owners read on Monday?": the pack of a week as it will arrive (in either language),
 * a "Send test to me" button, the settings (who gets it, which day and time, which language and channels, on or off) and the log of every send.
 * Company-wide by nature (it covers every branch), so it is for people who see every branch's reports and the settings. Aggregates only.
 *
 * @property array<string, mixed> $pack
 */
class OwnerPackPage extends Component
{
    use InteractsWithAdmin;

    #[Url(except: '')]
    public string $week = '';

    #[Url(except: '')]
    public string $lang = '';

    public bool $showSettings = false;

    /** @var array{enabled: bool, email_on: bool, emails: string, whatsapp_on: bool, numbers: string, day: string, time: string, language: string} */
    public array $form = ['enabled' => false, 'email_on' => true, 'emails' => '', 'whatsapp_on' => false, 'numbers' => '', 'day' => '1', 'time' => '08:00', 'language' => 'en'];

    public function mount(OwnerPackSettings $settings): void
    {
        abort_unless($this->allowed(), 403);
        $weeks = $this->weeks();
        if (! in_array($this->week, $weeks, true)) {
            $this->week = $weeks[0];
        }
        if (! in_array($this->lang, OwnerPackSettings::LANGUAGES, true)) {
            $this->lang = $settings->language();
        }
        $this->fillForm($settings);
    }

    private function allowed(): bool
    {
        return $this->canGlobally('reports.view') && $this->canGlobally('settings.view');
    }

    /** The last twelve finished weeks, newest first. @return list<string> */
    private function weeks(): array
    {
        $monday = BusinessTime::now()->startOfWeek()->subWeek();

        return array_map(fn (int $i) => OwnerPackData::weekKey($monday->subWeeks($i)), range(0, 11));
    }

    private function fillForm(OwnerPackSettings $s): void
    {
        $this->form = ['enabled' => $s->enabled(), 'email_on' => $s->emailOn(), 'emails' => implode("\n", $s->emails()), 'whatsapp_on' => $s->whatsappOn(),
            'numbers' => implode("\n", $s->numbers()), 'day' => (string) $s->day(), 'time' => $s->time(), 'language' => $s->language()];
    }

    public function openSettings(OwnerPackSettings $settings): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $this->fillForm($settings);
        $this->resetValidation();
        $this->showSettings = true;
    }

    public function save(Settings $settings): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $this->validate([
            'form.enabled' => 'boolean', 'form.email_on' => 'boolean', 'form.whatsapp_on' => 'boolean',
            'form.emails' => 'nullable|string|max:2000', 'form.numbers' => 'nullable|string|max:2000',
            'form.day' => 'required|integer|in:'.implode(',', OwnerPackSettings::DAYS), 'form.time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'form.language' => 'required|in:'.implode(',', OwnerPackSettings::LANGUAGES),
        ], [], ['form.emails' => __('owner_pack.f_emails'), 'form.numbers' => __('owner_pack.f_numbers'), 'form.day' => __('owner_pack.f_day'), 'form.time' => __('owner_pack.f_time'), 'form.language' => __('owner_pack.f_language')]);

        $emails = OwnerPackSettings::split((string) $this->form['emails']);
        foreach ($emails as $e) {
            if (filter_var($e, FILTER_VALIDATE_EMAIL) === false || strlen($e) > OwnerPackSettings::MAX_ADDRESS_LENGTH) {
                $this->addError('form.emails', __('owner_pack.err_email', ['value' => $e]));

                return;
            }
        }
        $numbers = [];
        foreach (OwnerPackSettings::split((string) $this->form['numbers'], spaces: false) as $raw) {
            $n = Phone::normalize($raw);
            if ($n === null) {
                $this->addError('form.numbers', __('owner_pack.err_number', ['value' => $raw]));

                return;
            }
            $numbers[] = $n;
        }
        $numbers = array_values(array_unique($numbers));
        if (count($emails) > OwnerPackSettings::MAX_RECIPIENTS || count($numbers) > OwnerPackSettings::MAX_RECIPIENTS) {
            $this->addError(count($emails) > OwnerPackSettings::MAX_RECIPIENTS ? 'form.emails' : 'form.numbers', __('owner_pack.err_too_many', ['max' => OwnerPackSettings::MAX_RECIPIENTS]));

            return;
        }
        if ($this->form['enabled'] && $this->form['email_on'] && $emails === []) {
            $this->addError('form.emails', __('owner_pack.err_no_email'));

            return;
        }
        if ($this->form['enabled'] && $this->form['whatsapp_on'] && $numbers === []) {
            $this->addError('form.numbers', __('owner_pack.err_no_number'));

            return;
        }

        $new = ['owner_pack.enabled' => (bool) $this->form['enabled'], 'owner_pack.email_enabled' => (bool) $this->form['email_on'], 'owner_pack.emails' => $emails,
            'owner_pack.whatsapp_enabled' => (bool) $this->form['whatsapp_on'], 'owner_pack.whatsapp_numbers' => $numbers, 'owner_pack.day' => (int) $this->form['day'],
            'owner_pack.time' => (string) $this->form['time'], 'owner_pack.language' => (string) $this->form['language']];
        $changed = 0;
        foreach ($new as $key => $value) {
            if ($settings->get($key) !== $value) {
                $settings->set($key, $value);
                $changed++;
            }
        }
        $this->showSettings = false;
        $this->toast($changed > 0 ? __('admin.saved') : __('owner_pack.unchanged'));
    }

    /** "Send test to me": the pack of the chosen week to the person who pressed it, on e-mail only. */
    public function sendTest(OwnerPackSender $sender): void
    {
        abort_unless($this->allowed(), 403);
        try {
            $send = $sender->sendTest(auth()->user(), $this->week);
        } catch (DomainException $e) {
            $this->toast($e->getMessage(), 'error');

            return;
        }
        $this->toast($send->status === 'sent' ? __('owner_pack.test_sent', ['email' => auth()->user()->email]) : __('owner_pack.test_failed'), $send->status === 'sent' ? 'success' : 'error');
    }

    /** The week and language on show (an unknown value shows the latest week or English, never an error page). @return array{0: string, 1: string} */
    private function shown(): array
    {
        return [in_array($this->week, $this->weeks(), true) ? $this->week : $this->weeks()[0], in_array($this->lang, OwnerPackSettings::LANGUAGES, true) ? $this->lang : 'en'];
    }

    /**
     * The pack on show, worked out once per week and language and kept for two minutes under this page's own key (never sent to the browser): opening the
     * settings or pressing a button does not build the whole pack again.
     *
     * @return array<string, mixed>
     */
    #[Computed(persist: true, seconds: 120)]
    public function pack(): array
    {
        [$week, $lang] = $this->shown();

        return app(OwnerPackSender::class)->pack($week, $lang);
    }

    public function updatedWeek(): void
    {
        unset($this->pack);
    }

    public function updatedLang(): void
    {
        unset($this->pack);
    }

    public function render(OwnerPackSettings $settings)
    {
        abort_unless($this->allowed(), 403);
        [$week, $lang] = $this->shown();
        $nextWeek = OwnerPackData::lastCompletedWeek();

        return view('livewire.admin.insights.owner-pack', [
            'pack' => $this->pack,
            'weeks' => $this->weeks(),
            'week' => $week,
            'lang' => $lang,
            'log' => OwnerPackSender::log(30),
            'enabled' => $settings->enabled(),
            'emails' => $settings->emails(),
            'numbers' => $settings->numbers(),
            'emailOn' => $settings->emailOn(),
            'whatsappOn' => $settings->whatsappOn(),
            'mailReady' => $settings->mailReady(),
            'whatsappReady' => $settings->whatsappReady(),
            'nextWeek' => $nextWeek,
            'due' => CarbonImmutable::parse($settings->dueAt($nextWeek))->setTimezone(BusinessTime::zone()),
            'canEdit' => $this->canGlobally('settings.edit'),
            'days' => OwnerPackSettings::DAYS,
            'sentThisWeek' => OwnerPackSend::query()->where('iso_week', $nextWeek)->where('kind', 'scheduled')->orderByDesc('created_at')->get(),
        ])->title(__('owner_pack.title'));
    }
}
