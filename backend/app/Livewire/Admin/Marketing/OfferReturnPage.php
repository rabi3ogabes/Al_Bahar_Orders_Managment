<?php

namespace App\Livewire\Admin\Marketing;

use App\Domain\Reports\Services\OfferReturn;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Marketing > Offer returns. The question of the page is "Did that coupon pay for itself?": across every discount given in the period, how much sales came
 * back for each 1 QAR given away, then one line per coupon, promotion, customer journey and sent campaign with its own answer in plain words. A line opens a
 * side window that spells the sum out, so "what does this number mean" is answered on the spot. The definitions are in {@see OfferReturn}.
 *
 * Company-wide by nature (an offer belongs to the whole company), so it is for people who may see marketing and every branch's reports. The report is run
 * once per choice of dates and kept for two minutes, never per click.
 *
 * @property array{overall: array<string, mixed>, offers: list<array<string, mixed>>} $report
 */
class OfferReturnPage extends Component
{
    use InteractsWithAdmin;

    public const KINDS = ['all', 'coupon', 'promotion', 'journey', 'campaign'];

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: 'all')]
    public string $kind = 'all';

    /** The offer open in the side window (its key), mirrored in the address. */
    #[Url(as: 'offer', except: '')]
    public string $offer = '';

    public bool $showOffer = false;

    public bool $showSettings = false;

    /** @var array{min_orders: string, campaign_days: string} */
    public array $settingsForm = ['min_orders' => '', 'campaign_days' => ''];

    public function mount(OfferReturn $returns): void
    {
        abort_unless($this->allowed(), 403);
        $today = BusinessTime::now();
        $this->from = $this->validDate($this->from) ? $this->from : $today->subDays(89)->toDateString();
        $this->to = $this->validDate($this->to) && $this->to >= $this->from ? $this->to : $today->toDateString();
        if (! in_array($this->kind, self::KINDS, true)) {
            $this->kind = 'all';
        }
        $this->settingsForm = ['min_orders' => (string) $returns->minOrders(), 'campaign_days' => (string) $returns->campaignDays()];
        $this->showOffer = $this->offer !== '' && collect($this->report['offers'])->contains('key', $this->offer);
        if (! $this->showOffer) {
            $this->offer = '';
        }
    }

    /** A page about the whole company's offers and sales: marketing and the reports of every branch. */
    private function allowed(): bool
    {
        return $this->canGlobally('marketing.view') && $this->canGlobally('reports.view');
    }

    private function validDate(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    public function preset(string $p): void
    {
        $now = BusinessTime::now();
        [$this->from, $this->to] = match ($p) {
            '30d' => [$now->subDays(29)->toDateString(), $now->toDateString()],
            '12m' => [$now->subMonths(12)->addDay()->toDateString(), $now->toDateString()],
            default => [$now->subDays(89)->toDateString(), $now->toDateString()],
        };
        unset($this->report);
    }

    public function updatedFrom(): void
    {
        unset($this->report);
    }

    public function updatedTo(): void
    {
        unset($this->report);
    }

    /** Opens one offer's window, but only an offer the report lists (a made-up key opens nothing). */
    public function open(string $key): void
    {
        if (! collect($this->report['offers'])->contains('key', $key)) {
            $this->offer = '';
            $this->showOffer = false;

            return;
        }
        $this->offer = $key;
        $this->showOffer = true;
    }

    /** Every way of closing the window (✕, Esc, scrim, Back) clears the offer from the address. */
    public function updatedShowOffer(bool $open): void
    {
        if (! $open) {
            $this->offer = '';
        }
    }

    public function saveSettings(Settings $settings): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $data = $this->validate(['settingsForm.min_orders' => 'required|integer|min:1|max:1000', 'settingsForm.campaign_days' => 'required|integer|min:1|max:60'], [],
            ['settingsForm.min_orders' => __('offer_return.min_orders_label'), 'settingsForm.campaign_days' => __('offer_return.campaign_days_label')])['settingsForm'];
        $settings->set('offer_return.min_orders', (int) $data['min_orders']);
        $settings->set('offer_return.campaign_days', (int) $data['campaign_days']);
        unset($this->report);
        $this->showSettings = false;
        $this->toast(__('admin.saved'));
    }

    /** The report for the chosen dates, run once and kept for two minutes under this page's own key (never sent to the browser). @return array{overall: array<string, mixed>, offers: list<array<string, mixed>>} */
    #[Computed(persist: true, seconds: 120)]
    public function report(): array
    {
        return app(OfferReturn::class)->report($this->from, $this->to);
    }

    public function render(OfferReturn $returns)
    {
        abort_unless($this->allowed(), 403);
        $report = $this->report;
        $offers = array_values(array_filter($report['offers'], fn (array $o) => $this->kind === 'all' || $o['kind'] === $this->kind));
        $open = $this->offer !== '' ? collect($report['offers'])->firstWhere('key', $this->offer) : null;

        return view('livewire.admin.marketing.offer-return', [
            'overall' => $report['overall'],
            'offers' => $offers,
            'counts' => collect($report['offers'])->countBy('kind')->all(),
            'open' => $open,
            'minOrders' => $returns->minOrders(),
            'campaignDays' => $returns->campaignDays(),
            'canSettings' => $this->canGlobally('settings.edit'),
            'kinds' => self::KINDS,
        ])->title(__('offer_return.title'));
    }
}
