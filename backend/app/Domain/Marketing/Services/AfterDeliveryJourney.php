<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\UpsellService;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyDelivery;
use App\Domain\Marketing\Models\JourneyRun;
use App\Domain\Marketing\Models\JourneyStep;
use App\Domain\Marketing\Models\JourneyTemplate;
use App\Domain\Marketing\Models\Review;
use App\Domain\Marketing\Support\ReminderText;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Support\Models\SupportTicket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The "After delivery" customer journey (docs/guides/ALERTS_AFTER_DELIVERY_AND_ROOMS.md): when an order is delivered the customer gets a short thank-you
 * (default 2 hours later), a review request (4 days later) that opens the review window for a product they have not reviewed, and an optional "goes well
 * with it" message (7 days later) with up to three products from the suggestion engine they have not bought. It rides on the journey engine (JourneyService:
 * runs, steps, deliveries, quiet hours, consent, the daily cap) and only adds what is specific to an order: who is worth a message, and what each message says.
 *
 * One run per delivered order (`journey_runs.trigger_order_id` is unique), made when the scheduler first sees the order: a customer is left out for good when the
 * order was returned or refunded, had a failed delivery, has a return or damage claim, or already got an after-delivery run in the last `cooldown_days`
 * (14 by default). A message is skipped (the run goes on) while the customer has an open support ticket, when every product is already reviewed, or when there
 * is nothing to suggest. Every one of these is read again at the moment a message would go out. Nothing is ever sent late: a step more than a day overdue is dropped.
 */
final class AfterDeliveryJourney
{
    public const KEY = 'after_delivery';

    public const THANK_YOU = 'thank_you';

    public const REVIEW = 'review';

    public const SUGGEST = 'suggest';

    /** In the order they happen. */
    public const KINDS = [self::THANK_YOU, self::REVIEW, self::SUGGEST];

    /** Minutes after the delivery: 2 hours, 4 days, 7 days. */
    public const DELAYS = [self::THANK_YOU => 120, self::REVIEW => 5760, self::SUGGEST => 10080];

    /** A message more than this many hours overdue (the scheduler was down, the quiet hours were long) is dropped, never sent late. */
    public const GRACE_HOURS = 24;

    public const DEFAULT_COOLDOWN_DAYS = 14;

    /** The most products a "goes well with it" message names. */
    public const MAX_SUGGESTIONS = 3;

    /** Orders looked at per scheduler pass; the rest follows on the next pass. */
    private const BATCH = 100;

    private const OPEN_TICKET_EXCLUDED = ['resolved', 'closed'];

    public function __construct(private readonly UpsellService $upsells) {}

    public static function cooldownDays(Journey $journey): int
    {
        return max(1, min(90, (int) $journey->setting('cooldown_days', self::DEFAULT_COOLDOWN_DAYS)));
    }

    // ── Starting point ───────────────────────────────────────────────────────────────────────────────────────────────

    /** The journey with its three steps and bilingual starter templates, switched off. Safe to call on every pass. */
    public function ensure(): Journey
    {
        $journey = Journey::where('key', self::KEY)->first();
        if ($journey !== null) {
            return $journey;
        }
        try {
            return DB::transaction(function () {
                $journey = Journey::create(['key' => self::KEY, 'name' => 'After delivery', 'is_enabled' => false,
                    'settings' => ['quiet_start' => '22:00', 'quiet_end' => '08:00', 'max_per_day' => 3, 'attribution_days' => 7, 'cooldown_days' => self::DEFAULT_COOLDOWN_DAYS]]);
                foreach (self::KINDS as $i => $kind) {
                    $d = $this->defaults()[$kind];
                    $template = JourneyTemplate::create(['name' => $d['name'], 'kind' => 'reminder', 'deep_link' => $d['deep_link'], 'title_en' => $d['title_en'], 'title_ar' => $d['title_ar'],
                        'body_en' => $d['body_en'], 'body_ar' => $d['body_ar']]);
                    // The optional third message ships switched off: the owner decides whether the store sends product suggestions.
                    JourneyStep::create(['journey_id' => $journey->id, 'position' => $i + 1, 'kind' => $kind, 'label' => $d['label'], 'delay_minutes' => self::DELAYS[$kind],
                        'channels' => ['push', 'in_app'], 'template_id' => $template->id, 'is_enabled' => $kind !== self::SUGGEST]);
                }

                return $journey;
            });
        } catch (UniqueConstraintViolationException) {
            return Journey::where('key', self::KEY)->firstOrFail(); // the scheduler and a page made it at the same moment
        }
    }

    /**
     * The shipped words. The Arabic was drafted by AI in a natural Gulf tone: have a native speaker read it before relying on it.
     *
     * @return array<string, array<string, string>>
     */
    public function defaults(): array
    {
        return [
            self::THANK_YOU => ['label' => 'Thank you', 'name' => 'After delivery: thank you', 'deep_link' => 'albahar://orders',
                'title_en' => 'Thank you, {{name}}', 'body_en' => 'Your order {{order_number}} has arrived. We hope you love it. If you need anything, we are here.',
                'title_ar' => 'شكراً لك يا {{name}}', 'body_ar' => 'وصل طلبك {{order_number}}. نتمنى أن ينال إعجابك، وإذا احتجت أي شيء نحن بخدمتك.'],
            self::REVIEW => ['label' => 'Review request', 'name' => 'After delivery: review request', 'deep_link' => 'albahar://orders',
                'title_en' => 'How is your {{product}}?', 'body_en' => 'Tell us what you think. It takes a minute and helps other families choose.',
                'title_ar' => 'كيف وجدت {{product}}؟', 'body_ar' => 'شاركنا رأيك، دقيقة واحدة تكفي وتساعد غيرك على الاختيار.'],
            self::SUGGEST => ['label' => 'Goes well with it', 'name' => 'After delivery: goes well with it', 'deep_link' => 'albahar://home',
                'title_en' => 'Goes well with your order', 'body_en' => '{{suggestions}} would go nicely with what you bought. Have a look when you have a minute.',
                'title_ar' => 'يليق مع طلبك', 'body_ar' => '{{suggestions}} تناسب ما اشتريته. ألقِ نظرة متى ما تيسّر لك.'],
        ];
    }

    // ── Runs ─────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Makes a run for every order delivered recently that has none yet (an order that is already known is skipped by one NOT EXISTS, so a pass costs about
     * what the new orders cost). Only orders a message can still reach are looked at: the horizon is the last enabled step plus the grace.
     *
     * @return int runs made (stopped ones included: the decision is recorded, and the order is never looked at again)
     */
    public function startRuns(Journey $journey): int
    {
        $first = max(1, (int) $journey->steps->min('delay_minutes'));
        $horizon = (int) $journey->steps->max('delay_minutes') + self::GRACE_HOURS * 60;
        $n = 0;
        Order::query()->where('status', 'completed')->whereNotNull('user_id')->whereNotNull('completed_at')->where('completed_at', '>=', now()->subMinutes($horizon))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journey_runs')->whereColumn('journey_runs.trigger_order_id', 'orders.id')->where('journey_runs.journey_id', $journey->id))
            ->orderBy('completed_at')->limit(self::BATCH)->with('user')->get()->each(function (Order $order) use ($journey, $first, &$n) {
                $block = $this->blockedAtStart($order, $journey);
                for ($shift = 0; $shift < 3; $shift++) { // two orders completed in the same second: the anchor is unique per customer, so the second one is nudged
                    try {
                        JourneyRun::create(['journey_id' => $journey->id, 'user_id' => $order->user_id, 'trigger_order_id' => $order->id, 'anchor_at' => $order->completed_at->addSeconds($shift),
                            'status' => $block === null ? 'active' : 'stopped', 'stop_reason' => $block, 'finished_at' => $block === null ? null : now(),
                            'next_at' => $block === null ? $order->completed_at->addMinutes($first) : null]);
                        $n++;

                        return;
                    } catch (UniqueConstraintViolationException) {
                        if (JourneyRun::where('journey_id', $journey->id)->where('trigger_order_id', $order->id)->exists()) {
                            return; // another pass made it
                        }
                    }
                }
            });

        return $n;
    }

    /** Why this order's customer is left out for good, or null. */
    private function blockedAtStart(Order $order, Journey $journey): ?string
    {
        if ($order->user === null || ! $order->user->isActive()) {
            return 'inactive_user';
        }
        $permanent = $this->orderProblem($order);
        if ($permanent !== null) {
            return $permanent;
        }
        $window = self::cooldownDays($journey);
        $recent = JourneyRun::where('journey_id', $journey->id)->where('user_id', $order->user_id)->where('status', '<>', 'stopped')
            ->where('trigger_order_id', '<>', $order->id)->whereBetween('anchor_at', [$order->completed_at->subDays($window), $order->completed_at->addDays($window)])->exists();

        return $recent ? 'recent_journey' : null;
    }

    /** The things that end the journey for an order: returned, refunded, a failed delivery, a return or damage claim. */
    private function orderProblem(Order $order): ?string
    {
        if ($order->status->value !== 'completed') {
            return 'order_changed'; // returned, partly cancelled or cancelled since
        }
        if ((float) $order->refunded_total > 0) {
            return 'refunded';
        }
        if (ReturnRequest::where('order_id', $order->id)->where('status', '<>', 'cancelled')->exists()) {
            return 'return_claim';
        }
        $failed = DB::table('deliveries as d')->join('order_fulfillments as f', 'f.id', '=', 'd.order_fulfillment_id')->where('f.order_id', $order->id)
            ->where(fn ($q) => $q->whereNotNull('d.failed_at')->orWhereIn('d.status', ['failed', 'returning', 'returned']))->exists();

        return $failed ? 'delivery_problem' : null;
    }

    // ── One message ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Is this message worth sending to this customer right now? Read fresh at the moment of sending.
     *  - stop: the run ends (the order was returned or refunded, a claim was opened, a delivery failed);
     *  - skip: this message is not sent, the next one may be (an open support ticket, nothing to review, nothing to suggest);
     *  - view: the message's own words and links.
     *
     * @return array{stop?: string, skip?: string, view?: array<string, string>}
     */
    public function gate(JourneyRun $run, JourneyStep $step): array
    {
        $order = $run->trigger_order_id ? Order::with(['items', 'fulfillments'])->find($run->trigger_order_id) : null;
        if ($order === null) {
            return ['stop' => 'order_changed'];
        }
        if (($problem = $this->orderProblem($order)) !== null) {
            return ['stop' => $problem];
        }
        if (SupportTicket::where('user_id', $run->user_id)->whereNotIn('status', self::OPEN_TICKET_EXCLUDED)->exists()) {
            return ['skip' => 'open_ticket']; // somebody is waiting for an answer: a "how are we doing?" message now would be tone-deaf
        }
        $user = $run->user ?? User::findOrFail($run->user_id);

        return match ($step->kind) {
            self::REVIEW => $this->reviewMessage($order, $user),
            self::SUGGEST => $this->suggestionMessage($order, $user),
            default => ['view' => $this->base($order, $user) + ['deep_link' => 'albahar://orders/'.$order->id, 'link' => $this->site().'/orders/'.$order->id]],
        };
    }

    /** @return array{skip?: string, view?: array<string, string>} */
    private function reviewMessage(Order $order, User $user): array
    {
        $products = $this->unreviewed($order, $user);
        if ($products->isEmpty()) {
            return ['skip' => 'nothing_to_review'];
        }
        $first = $products->first();
        $locale = $user->preferredLocale();

        return ['view' => $this->base($order, $user) + ['product' => (string) $first->trans('name', $locale), 'first_item' => (string) $first->trans('name', $locale),
            'deep_link' => 'albahar://products/'.$first->id.'?review=1', 'link' => $this->site().'/products/'.($first->trans('slug') ?: $first->id).'?review=1']];
    }

    /** @return array{skip?: string, view?: array<string, string>} */
    private function suggestionMessage(Order $order, User $user): array
    {
        $bought = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')->where('o.user_id', $user->id)->whereNotNull('oi.product_id')
            ->whereNotIn('o.status', ['cancelled', 'pending_payment'])->distinct()->pluck('oi.product_id')->all();
        $branch = $order->fulfillments->first()?->branch_id;
        $cards = $this->upsells->forProducts($order->items->pluck('product_id')->filter()->unique()->values()->all(), $bought, $branch ? Branch::find($branch) : null, self::MAX_SUGGESTIONS);
        if ($cards === []) {
            return ['skip' => 'nothing_to_suggest'];
        }
        $locale = $user->preferredLocale();
        $names = Product::with('translations')->whereIn('id', collect($cards)->pluck('id'))->get()->keyBy('id');
        $ordered = collect($cards)->map(fn (array $c) => $names->get($c['id']))->filter();
        $first = $ordered->first();

        return ['view' => $this->base($order, $user) + ['suggestions' => ReminderText::joinNames($ordered->map(fn (Product $p) => (string) $p->trans('name', $locale))->all(), $locale),
            'product' => (string) $first?->trans('name', $locale), 'first_item' => (string) $first?->trans('name', $locale),
            'deep_link' => 'albahar://products/'.$first?->id, 'link' => $this->site().'/products/'.($first?->trans('slug') ?: $first?->id)]];
    }

    /**
     * The products of the order the customer received and can still review: active in the catalogue, handed over, and without a review of theirs
     * (a review that is still being moderated counts as theirs). In the order the lines were bought.
     *
     * @return Collection<int, Product>
     */
    public function unreviewed(Order $order, User $user): Collection
    {
        $ids = $order->items->filter(fn (OrderItem $i) => $i->product_id !== null && (int) $i->quantity_handed_over > 0)->pluck('product_id')->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $reviewed = Review::where('user_id', $user->id)->whereIn('product_id', $ids)->pluck('product_id')->all();
        $products = Product::active()->with('translations')->whereIn('id', array_values(array_diff($ids->all(), $reviewed)))->get()->keyBy('id');

        return $ids->map(fn ($id) => $products->get($id))->filter()->values();
    }

    /** @return array<string, string> */
    private function base(Order $order, User $user): array
    {
        $count = $order->items->filter(fn (OrderItem $i) => (int) $i->quantity_handed_over > 0)->pluck('product_id')->unique()->count();

        return ['order_number' => (string) $order->number, 'items' => (string) $count, 'items_text' => ReminderText::itemsText($count, $user->preferredLocale())];
    }

    private function site(): string
    {
        return rtrim((string) (config('platform.storefront_url') ?: config('app.url')), '/');
    }

    // ── For the journey page ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Messages sent and opened per step in the last `$days` days: "sent" counts customers reached (not channels), "opened" counts customers whose inbox
     * entry was read (a push alone cannot be told apart, so it is not counted).
     *
     * @return array<string, array{sent: int, opened: int}> step id => counts
     */
    public function stepStats(Journey $journey, int $days = 30): array
    {
        $since = now()->subDays($days);
        $stepIds = $journey->steps()->pluck('id');
        $sent = JourneyDelivery::whereIn('step_id', $stepIds)->whereIn('status', ['sent', 'delivered'])->where('sent_at', '>=', $since)
            ->groupBy('step_id')->selectRaw('step_id, count(distinct run_id) as n')->pluck('n', 'step_id');
        $opened = DB::table('journey_deliveries as d')->join('notifications as n', 'n.id', '=', 'd.notification_id')->whereIn('d.step_id', $stepIds)
            ->whereIn('d.status', ['sent', 'delivered'])->where('d.sent_at', '>=', $since)->whereNotNull('n.read_at')
            ->groupBy('d.step_id')->selectRaw('d.step_id, count(distinct d.run_id) as n')->pluck('n', 'd.step_id');

        return $stepIds->mapWithKeys(fn ($id) => [$id => ['sent' => (int) ($sent[$id] ?? 0), 'opened' => (int) ($opened[$id] ?? 0)]])->all();
    }
}
