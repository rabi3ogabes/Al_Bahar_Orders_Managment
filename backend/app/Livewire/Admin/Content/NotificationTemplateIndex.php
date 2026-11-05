<?php

namespace App\Livewire\Admin\Content;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Notifications\Services\NotificationCatalog;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Illuminate\Support\Facades\Lang;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Notification templates: event × channel × locale overrides on top of the built-in lang defaults, with the
 * available {{variables}} and a live preview rendered by TemplateRenderer (the same code path real sends use).
 */
class NotificationTemplateIndex extends Component
{
    use InteractsWithAdmin;

    /** Sample placeholder values used for previews. */
    public const SAMPLE = [
        'number' => 'AB-260412-0042', 'reference' => 'AB-260412-0042', 'name' => 'Noor', 'customer_name' => 'Noor Al-Mansoori', 'amount' => 'QAR 4,490.00',
        'branch' => 'West Bay Showroom', 'code' => '482913', 'minutes' => '5', 'title' => 'Ramadan Collection', 'body' => 'Up to 30% off selected majlis sets.',
        'lot' => 'Carved Cedar Chest', 'product' => 'Arabesque Sofa', 'price' => 'QAR 3,990.00', 'due' => '12 Apr, 18:00', 'deep_link' => 'albahar://orders/…',
    ];

    #[Url(except: '')]
    public string $event = '';

    public string $channel = 'push';

    public string $locale = 'en';

    public bool $showEditor = false;

    public array $form = ['subject' => '', 'body' => '', 'is_active' => true];

    public array $sample = [];

    public function mount(): void
    {
        abort_unless($this->can('notifications.view'), 403);
        if (! array_key_exists($this->event, NotificationCatalog::EVENTS)) {
            $this->event = 'order.created';
        }
        $this->sample = self::SAMPLE;
    }

    public function pick(string $event): void
    {
        abort_unless(array_key_exists($event, NotificationCatalog::EVENTS), 404);
        $this->event = $event;
    }

    /** Built-in lang copy for an event, or '' when the platform ships none (e.g. OTP is composed by the SMS flow). */
    public static function builtIn(string $event, string $part, string $locale): string
    {
        $key = 'notifications.'.$event.'.'.$part;

        return Lang::has($key, $locale, false) ? (string) trans($key, [], $locale) : '';
    }

    /** Placeholders used by the default copy for this event (plus any in the override being edited). */
    public static function variables(string $event, string $extra = ''): array
    {
        $text = implode(' ', [self::builtIn($event, 'title', 'en'), self::builtIn($event, 'body', 'en'), $extra]);
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $text, $m);

        return array_values(array_unique($m[1]));
    }

    public function edit(string $channel, string $locale): void
    {
        abort_unless($this->can('notifications.edit') && in_array($channel, NotificationCatalog::CHANNELS, true) && in_array($locale, ['en', 'ar'], true), 403);
        $this->channel = $channel;
        $this->locale = $locale;
        $tpl = NotificationTemplate::where(['event' => $this->event, 'channel' => $channel, 'locale' => $locale])->first();
        $this->form = $tpl ? ['subject' => (string) $tpl->subject, 'body' => $tpl->body, 'is_active' => (bool) $tpl->is_active]
            : ['subject' => self::builtIn($this->event, 'title', $locale), 'body' => self::builtIn($this->event, 'body', $locale), 'is_active' => true];
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless($this->can('notifications.edit'), 403);
        $this->validate(['form.subject' => 'nullable|string|max:255', 'form.body' => 'required|string|max:2000', 'form.is_active' => 'boolean'], [], ['form.body' => __('content_admin.message_body')]);
        $tpl = NotificationTemplate::firstOrNew(['event' => $this->event, 'channel' => $this->channel, 'locale' => $this->locale]);
        $old = $tpl->exists ? $tpl->only(['subject', 'body', 'is_active']) : [];
        $tpl->fill(['subject' => $this->form['subject'] ?: null, 'body' => $this->form['body'], 'is_active' => (bool) $this->form['is_active']])->save();
        $audit->log($old ? 'notifications.template_updated' : 'notifications.template_created', $tpl, $old, $tpl->only(['subject', 'body', 'is_active']), ['event' => $this->event, 'channel' => $this->channel, 'locale' => $this->locale]);
        $this->showEditor = false;
        $this->toast(__('content_admin.template_saved'));
    }

    public function resetDefault(string $channel, string $locale, AuditLogger $audit): void
    {
        abort_unless($this->can('notifications.edit'), 403);
        $tpl = NotificationTemplate::where(['event' => $this->event, 'channel' => $channel, 'locale' => $locale])->first();
        if ($tpl) {
            $snap = $tpl->only(['event', 'channel', 'locale', 'subject', 'body']);
            $tpl->delete();
            $audit->log('notifications.template_reset', 'NotificationTemplate', $snap);
        }
        $this->showEditor = false;
        $this->toast(__('content_admin.template_reset'));
    }

    /** Preview: what TemplateRenderer would send right now for each locale (saved overrides or defaults). */
    public function previews(TemplateRenderer $renderer): array
    {
        $out = [];
        foreach (['en', 'ar'] as $l) {
            $r = $renderer->render($this->event, $this->channel, $l, $this->sample);
            // TemplateRenderer echoes the lang key when there is neither an override nor built-in copy.
            $out[$l] = str_starts_with($r['body'], 'notifications.') ? null : $r;
        }

        return $out;
    }

    public function render(TemplateRenderer $renderer)
    {
        $overrides = NotificationTemplate::where('event', $this->event)->get()->keyBy(fn ($t) => $t->channel.'|'.$t->locale);
        $fill = fn (string $s) => preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => (string) ($this->sample[$m[1]] ?? '{{'.$m[1].'}}'), $s);

        return view('livewire.admin.content.notification-templates', [
            'events' => NotificationCatalog::EVENTS,
            'counts' => NotificationTemplate::selectRaw('event, count(*) as n')->groupBy('event')->pluck('n', 'event'),
            'overrides' => $overrides,
            'defaults' => NotificationCatalog::defaultChannels($this->event),
            'variables' => self::variables($this->event, ($this->form['subject'] ?? '').' '.($this->form['body'] ?? '')),
            'previews' => $this->previews($renderer),
            'draftPreview' => $this->showEditor ? ['subject' => $fill((string) $this->form['subject']), 'body' => $fill((string) $this->form['body'])] : null,
            'canEdit' => $this->can('notifications.edit'),
        ])->title(__('nav.notification_templates'));
    }
}
