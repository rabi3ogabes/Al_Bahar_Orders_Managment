<?php

namespace App\Livewire\Admin\Content;

use App\Domain\Notifications\Services\NotificationCatalog;
use App\Domain\Notifications\Services\NotificationChannelStatus;
use App\Domain\Notifications\Services\NotificationRules;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\DomainException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Content > Notification controls: the admin decides which notifications are sent and on which channels (see NotificationRules).
 * Every switch is instant (saved at once, audited, a toast with Undo); there is no Save button. Opening needs notifications.view,
 * changing anything needs notifications.edit (checked again in every action). Channels that are not set up on the server are
 * greyed out with a link to where to set them up, never silently.
 */
class NotificationRulesIndex extends Component
{
    use InteractsWithAdmin;

    /** Notifications that always have a warning chip while switched off, by event => the key under `notification_rules_admin.warn`. */
    private const WARNINGS = [
        'payment.link' => 'payment_link', 'payment.failed' => 'payment_failed', 'refund.processed' => 'refund_processed',
        'order.items_changed' => 'order_items_changed', 'campaign' => 'campaign',
    ];

    /** The last two steps of the live card: switching their card off leaves the card on the step before. */
    private const FINAL_CARD_EVENTS = ['order.completed', 'order.cancelled'];

    /** @var list<string> the groups shown; empty = all */
    #[Url(as: 'group', except: [])]
    public array $only = [];

    #[Url(except: '')]
    public string $q = '';

    public function mount(): void
    {
        abort_unless($this->can('notifications.view'), 403);
    }

    public function toggleGroup(string $group): void
    {
        abort_unless(array_key_exists($group, NotificationRules::GROUPS), 404);
        $this->only = in_array($group, $this->only, true) ? array_values(array_diff($this->only, [$group])) : [...$this->only, $group];
    }

    public function clearFilters(): void
    {
        $this->only = [];
        $this->q = '';
    }

    public function toggleMaster(string $event, NotificationRules $rules): void
    {
        $this->authorizeEdit($event, $rules);
        $state = $rules->state($event);
        $on = ! $state['enabled'];
        $this->apply($rules, $event, $on, $state['channels'], __($on ? 'notification_rules_admin.toast_master_on' : 'notification_rules_admin.toast_master_off', ['name' => $this->name($event)]));
    }

    public function toggleChannel(string $event, string $channel, NotificationRules $rules): void
    {
        $this->authorizeEdit($event, $rules);
        abort_unless(in_array($channel, $rules->supportedChannels($event), true), 404);
        $state = $rules->state($event);
        $channels = $state['channels'];
        $on = ! ($channels[$channel] ?? in_array($channel, $rules->defaultChannels($event), true));
        $channels[$channel] = $on;
        $this->apply($rules, $event, $state['enabled'], $channels, __($on ? 'notification_rules_admin.toast_channel_on' : 'notification_rules_admin.toast_channel_off',
            ['name' => $this->name($event), 'channel' => __('notification_rules_admin.channels.'.$channel)]));
    }

    public function resetRule(string $event, NotificationRules $rules): void
    {
        $this->authorizeEdit($event, $rules);
        $before = $rules->state($event);
        if ($rules->reset($event)) {
            $this->toastWithUndo(__('notification_rules_admin.toast_reset', ['name' => $this->name($event)]), $event, $before);
        }
    }

    public function resetAll(NotificationRules $rules): void
    {
        abort_unless($this->can('notifications.edit'), 403);
        $rules->resetAll();
        $this->toast(__('notification_rules_admin.toast_reset_all'));
    }

    /** The Undo button of a toast: puts the event back exactly as it was before the change. */
    #[On('notification-rule-undo')]
    public function undo(string $rule, bool $enabled, array $channels, bool $customized, NotificationRules $rules): void
    {
        $this->authorizeEdit($rule, $rules);
        try {
            if ($customized) {
                $rules->set($rule, $enabled, $channels);
            } else {
                $rules->reset($rule);
            }
        } catch (DomainException $e) {
            $this->toast($e->getMessage(), 'error');

            return;
        }
        $this->toast(__('notification_rules_admin.toast_undone'));
    }

    public function render(NotificationRules $rules, NotificationChannelStatus $channelStatus)
    {
        $ready = $channelStatus->ready();
        $counts = $rules->sentCounts();
        $needle = Str::lower(trim($this->q));
        $sections = [];
        $total = 0;
        $anyCustomized = false;
        foreach ($rules->matrix() as $group) {
            $rows = [];
            foreach ($group['events'] as $row) {
                $anyCustomized = $anyCustomized || $row['customized'];
                $view = $this->present($rules, $row, $ready, $counts);
                if (($this->only === [] || in_array($group['group'], $this->only, true)) && ($needle === '' || str_contains($view['haystack'], $needle))) {
                    $rows[] = $view;
                }
            }
            if ($rows !== []) {
                $total += count($rows);
                $sections[] = ['group' => $group['group'], 'label' => __('notification_rules_admin.groups.'.$group['group']), 'rows' => $rows];
            }
        }

        return view('livewire.admin.content.notification-rules', [
            'sections' => $sections,
            'groupChips' => array_map(fn (string $g) => ['key' => $g, 'label' => __('notification_rules_admin.groups.'.$g)], array_keys(NotificationRules::GROUPS)),
            'total' => $total,
            'columns' => NotificationRules::CHANNELS,
            'channelStatus' => $ready,
            'setup' => $this->setupNeeded($ready),
            'alwaysSent' => $this->alwaysSent(),
            'anyCustomized' => $anyCustomized,
            'canEdit' => $this->can('notifications.edit'),
        ])->title(__('notification_rules_admin.title'));
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────────────────────

    private function authorizeEdit(string $event, NotificationRules $rules): void
    {
        abort_unless($this->can('notifications.edit'), 403);
        abort_unless($rules->known($event), 404);
    }

    /**
     * Saves one change and tells the admin with a toast that can take it back.
     *
     * @param  array<string, bool>  $channels
     */
    private function apply(NotificationRules $rules, string $event, bool $enabled, array $channels, string $message): void
    {
        $before = $rules->state($event);
        try {
            $rules->set($event, $enabled, $channels);
        } catch (DomainException $e) {
            $this->toast($e->getMessage(), 'error'); // a locked notification (sign-in codes)

            return;
        }
        $this->toastWithUndo($message, $event, $before);
    }

    /** @param  array{enabled: bool, channels: array<string, bool>, customized: bool}  $before */
    private function toastWithUndo(string $message, string $event, array $before): void
    {
        $this->dispatch('toast', type: 'success', message: $message, undoLabel: __('admin.undo'), undoEvent: 'notification-rule-undo',
            undoParams: ['rule' => $event, 'enabled' => $before['enabled'], 'channels' => $before['channels'], 'customized' => $before['customized']]);
    }

    /** The lang key of an event: order.ready_for_pickup => order_ready_for_pickup. */
    private function key(string $event): string
    {
        return str_replace('.', '_', $event);
    }

    private function name(string $event): string
    {
        return __('notification_rules_admin.ev.'.$this->key($event).'.name');
    }

    /**
     * One row of the page: the saved state of the event plus its words, warnings, counter and what is set up on the server.
     *
     * @param  array<string, mixed>  $row  one entry of NotificationRules::matrix()
     * @param  array<string, bool>  $ready  channel => set up on this server
     * @param  array<string, int>  $counts  messages delivered in the last 30 days, by event
     * @return array<string, mixed>
     */
    private function present(NotificationRules $rules, array $row, array $ready, array $counts): array
    {
        $event = (string) $row['event'];
        $key = $this->key($event);
        $enabled = (bool) $row['enabled'];
        $channels = [];
        foreach ((array) $row['channels'] as $channel => $c) {
            $channels[$channel] = $c + ['ready' => $ready[$channel] ?? false, 'label' => __('notification_rules_admin.channels.'.$channel), 'hint' => __('notification_rules_admin.channel_hints.'.$channel)];
        }
        $warnings = [];
        if (! $enabled && isset(self::WARNINGS[$event])) {
            $warnings[] = self::WARNINGS[$event];
        }
        $cardOff = in_array(NotificationRules::LIVE_CARD, $rules->supportedChannels($event), true) && ! $rules->allows($event, NotificationRules::LIVE_CARD);
        if ($cardOff && in_array($event, self::FINAL_CARD_EVENTS, true)) {
            $warnings[] = 'live_card_final';
        }
        $external = array_filter(['push', 'sms', 'whatsapp', 'email'], fn (string $c) => $channels[$c]['supported'] && $channels[$c]['on']);
        $sent = ! $row['locked'] && ! in_array($event, NotificationRules::STAFF_EVENTS, true) && ($external !== [] || ($counts[$event] ?? 0) > 0) ? ($counts[$event] ?? 0) : null;
        $name = $this->name($event);
        $when = __('notification_rules_admin.ev.'.$key.'.when');

        return array_merge($row, [
            'key' => $key, 'name' => $name, 'when' => $when, 'channels' => $channels, 'warnings' => $warnings, 'sent' => $sent,
            'card_note' => in_array($event, NotificationCatalog::LIVE_CARD_EVENTS, true),
            'not_sent' => in_array($event, NotificationRules::NOT_SENT_YET, true),
            'template' => ! $row['locked'] && ! in_array($event, NotificationRules::STAFF_EVENTS, true),
            // The search looks in both languages and at the event key, whichever language the dashboard is in.
            'haystack' => Str::lower(implode(' ', [$event, trans('notification_rules_admin.ev.'.$key.'.name', [], 'en'), trans('notification_rules_admin.ev.'.$key.'.name', [], 'ar'),
                trans('notification_rules_admin.ev.'.$key.'.when', [], 'en'), trans('notification_rules_admin.ev.'.$key.'.when', [], 'ar')])),
        ]);
    }

    /**
     * The channels that cannot send on this server yet, each with the page that sets it up (null when the person may not open it).
     *
     * @param  array<string, bool>  $ready
     * @return list<array{channel: string, label: string, url: ?string}>
     */
    private function setupNeeded(array $ready): array
    {
        $out = [];
        foreach (NotificationRules::CHANNELS as $channel) {
            if ($ready[$channel] ?? false) {
                continue;
            }
            $route = in_array($channel, ['push', NotificationRules::LIVE_CARD], true) ? 'admin.push.index' : 'admin.settings.index';
            $out[] = ['channel' => $channel, 'label' => __('notification_rules_admin.channels.'.$channel),
                'url' => Route::has($route) && $this->can('settings.view') ? route($route) : null];
        }

        return $out;
    }

    /** @return list<array{name: string, hint: string, url: ?string}> the deliberate sends that are not switched here */
    private function alwaysSent(): array
    {
        return [
            ['name' => __('notification_rules_admin.always_journeys'), 'hint' => __('notification_rules_admin.always_journeys_hint'),
                'url' => Route::has('admin.journeys.index') && $this->can('marketing.view') ? route('admin.journeys.index') : null],
            ['name' => __('notification_rules_admin.always_messages'), 'hint' => __('notification_rules_admin.always_messages_hint'),
                'url' => Route::has('admin.messages.index') ? route('admin.messages.index') : null],
        ];
    }
}
