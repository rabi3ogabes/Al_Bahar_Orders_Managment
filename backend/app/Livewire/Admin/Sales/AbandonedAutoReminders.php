<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Checkout\Services\AbandonedCartService;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Services\AbandonedCartReminders;
use App\Domain\Marketing\Support\ReminderText;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\DomainException;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Automatic reminders" on the abandoned orders page: the owner's one big switch, a plain sentence saying exactly what it does, the results of the last
 * 7 or 30 days and the "Reminder settings" side window (when, how often, quiet hours, ways to reach the customer, an optional offer and the words of each
 * reminder in English and Arabic with a live preview).
 *
 * It decides nothing itself: AbandonedCartReminders reads, validates and saves, and JourneyService is the one engine that sends.
 *  - Everybody who can open the page sees the card and the results of their own branches; only `marketing.edit` company-wide (the people who may message
 *    all customers) can switch it or change the settings, checked again on every call.
 *  - The switch saves at once (a toast with Undo, no Save button). The window's choices wait for Save, which stays disabled until something changed.
 */
class AbandonedAutoReminders extends Component
{
    use InteractsWithAdmin;

    public bool $showSettings = false;

    /** @var array<string, mixed> the settings being edited in the window (see AbandonedCartReminders::form) */
    public array $form = [];

    /** @var array<string, mixed> what was saved when the window opened: "changed" means form differs from it */
    #[Locked]
    public array $saved = [];

    /** when | messages */
    public string $tab = 'when';

    /** The reminder (1-3) whose words are being edited and previewed. */
    public int $editing = 1;

    public string $previewLocale = 'en';

    /** Days of results shown: 7 or 30. */
    public int $days = 7;

    public function mount(): void
    {
        abort_unless(app(AbandonedCartService::class)->canView(auth()->user()), 403);
        $this->previewLocale = app()->getLocale() === 'ar' ? 'ar' : 'en';
    }

    private function reminders(): AbandonedCartReminders
    {
        return app(AbandonedCartReminders::class);
    }

    /** Company-wide marketing editors only: these settings message every customer, whichever branch they shop at. */
    private function editor(): bool
    {
        return app(AccessControl::class)->allowsGlobally(auth()->user(), 'marketing.edit');
    }

    private function requireEditor(): void
    {
        abort_unless($this->editor() && app(AbandonedCartService::class)->canView(auth()->user()), 403);
    }

    // ── The switch ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function toggle(): void
    {
        $this->requireEditor();
        $this->switchTo(! $this->reminders()->isEnabled());
    }

    #[On('cart-reminders-undo')]
    public function undoToggle(bool $on): void
    {
        $this->requireEditor();
        try {
            $this->reminders()->setEnabled($on, auth()->user());
            $this->toast($on ? __('cart_reminders.turned_on') : __('cart_reminders.turned_off'));
        } catch (DomainException $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    private function switchTo(bool $on): void
    {
        try {
            $before = $this->reminders()->setEnabled($on, auth()->user());
        } catch (DomainException $e) {
            $this->toast($e->getMessage(), 'error');

            return;
        }
        if ($before === $on) {
            return;
        }
        $this->dispatch('toast', type: 'success', message: $on ? __('cart_reminders.turned_on') : __('cart_reminders.turned_off'),
            undoLabel: __('admin.undo'), undoEvent: 'cart-reminders-undo', undoParams: ['on' => $before]);
    }

    public function setDays(int $days): void
    {
        $this->days = $days === 30 ? 30 : 7;
    }

    // ── The settings window ──────────────────────────────────────────────────────────────────────────────────────────

    public function openSettings(): void
    {
        $this->requireEditor();
        $this->form = AbandonedCartReminders::canonical($this->reminders()->form());
        $this->saved = $this->form;
        $this->tab = 'when';
        $this->editing = 1;
        $this->resetErrorBag();
        $this->showSettings = true;
    }

    /** Whatever changes in the window, the delays are put back in order (each later reminder at least 3 hours after the one before) and the types stay right. */
    public function updatedForm(): void
    {
        $form = AbandonedCartReminders::normalised(AbandonedCartReminders::canonical($this->form));
        if (! ($form['reminders'][$this->offerSlot($form)]['enabled'] ?? true)) {
            $form['offer']['reminder'] = (int) collect($form['reminders'])->search(fn (array $r) => $r['enabled']) + 1;
        }
        $this->form = $form;
        $this->resetErrorBag();
    }

    /** @param  array<string, mixed>  $form */
    private function offerSlot(array $form): int
    {
        return max(0, min(AbandonedCartReminders::SLOTS - 1, (int) ($form['offer']['reminder'] ?? 1) - 1));
    }

    public function preset(string $key): void
    {
        $this->requireEditor();
        $this->form = AbandonedCartReminders::withPreset(AbandonedCartReminders::canonical($this->form), $key);
        $this->resetErrorBag();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'messages' ? 'messages' : 'when';
    }

    public function setEditing(int $number): void
    {
        $this->editing = max(1, min(AbandonedCartReminders::SLOTS, $number));
    }

    public function setPreviewLocale(string $locale): void
    {
        $this->previewLocale = $locale === 'ar' ? 'ar' : 'en';
    }

    /** Puts the words of the reminder being edited back to the ones the platform ships with. */
    public function resetText(): void
    {
        $this->requireEditor();
        $i = $this->editing - 1;
        $default = $this->reminders()->defaults()['reminders'][$i];
        foreach (['title_en', 'body_en', 'title_ar', 'body_ar'] as $field) {
            $this->form['reminders'][$i][$field] = $default[$field];
        }
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->requireEditor();
        try {
            $this->reminders()->save($this->form, auth()->user());
        } catch (ValidationException $e) {
            $this->setErrorBag($this->prefixed($e));
            $this->tab = $this->firstErrorTab($e);
            $this->toast(__('cart_reminders.fix_errors'), 'error');

            return;
        }
        $this->saved = $this->form = AbandonedCartReminders::canonical($this->reminders()->form());
        $this->showSettings = false;
        $this->toast(__('cart_reminders.saved'));
    }

    /** @return MessageBag the errors under the keys of the form fields (`form.reminders.1.delay`) */
    private function prefixed(ValidationException $e): MessageBag
    {
        $bag = new MessageBag;
        foreach ($e->errors() as $key => $messages) {
            foreach ($messages as $m) {
                $bag->add('form.'.$key, $m);
            }
        }

        return $bag;
    }

    private function firstErrorTab(ValidationException $e): string
    {
        $key = (string) array_key_first($e->errors());

        return preg_match('/^reminders\.\d+\.(title|body)_/', $key) ? 'messages' : 'when';
    }

    // ── The page ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function render()
    {
        $svc = $this->reminders();
        $enabled = $svc->isEnabled();
        $settings = AbandonedCartReminders::canonical($svc->form());
        $data = [
            'enabled' => $enabled,
            'canEdit' => $this->editor(),
            'summary' => $svc->summary($settings),
            'settings' => $settings,
            'blocked' => $svc->blockedBy(),
            'ready' => $svc->channelStatus(),
            'results' => $svc->results(auth()->user(), $this->days),
            'window' => $this->showSettings ? $this->windowData($svc) : null,
        ];

        return view('livewire.admin.sales.abandoned-auto-reminders', $data);
    }

    /** @return array<string, mixed> */
    private function windowData(AbandonedCartReminders $svc): array
    {
        $form = $this->form;
        $i = $this->editing - 1;
        $reminder = $form['reminders'][$i] ?? $svc->defaults()['reminders'][$i];
        $locale = $this->previewLocale;
        $withOffer = ($form['offer']['promotion_id'] ?? '') !== '' && $this->offerSlot($form) === $i;
        $vars = ReminderText::sample($locale, $withOffer);
        $title = ReminderText::fill((string) ($reminder['title_'.$locale] ?? ''), $vars);
        $raw = (string) ($reminder['title_'.$locale] ?? '').' '.(string) ($reminder['body_'.$locale] ?? '');
        $body = ReminderText::withOffer(ReminderText::fill((string) ($reminder['body_'.$locale] ?? ''), $vars), $raw, $vars, $locale);
        $previous = 0;
        $choices = [];
        foreach ($form['reminders'] as $n => $r) {
            $choices[$n] = AbandonedCartReminders::delayChoices($n, $previous, (int) $r['delay']);
            $previous = (int) $r['delay'];
        }
        $promotions = Promotion::query()->where('is_active', true)->where('requires_coupon', true)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('name_en')->get(['id', 'name_en', 'name_ar', 'type']);

        return [
            'dirty' => AbandonedCartReminders::canonical($form) != AbandonedCartReminders::canonical($this->saved),
            'summary' => $svc->summary(AbandonedCartReminders::canonical($form)),
            'preset' => AbandonedCartReminders::matchingPreset($form),
            'choices' => $choices,
            'promotions' => $promotions,
            'preview' => ['title' => $title, 'body' => $body, 'locale' => $locale],
            'customWhatsApp' => $this->hasOwnWhatsApp($svc, $i),
            'offerSlot' => $this->offerSlot($form),
            'hasOffer' => ($form['offer']['promotion_id'] ?? '') !== '',
        ];
    }

    /** Does this reminder have its own WhatsApp wording (written on the journeys page)? The window does not change it, so it says so. */
    private function hasOwnWhatsApp(AbandonedCartReminders $svc, int $i): bool
    {
        $t = $svc->journey()->steps->values()[$i]->template ?? null;

        return $t !== null && ($t->whatsapp_en || $t->whatsapp_ar);
    }
}
