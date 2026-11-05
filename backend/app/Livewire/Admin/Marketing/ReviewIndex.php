<?php

namespace App\Livewire\Admin\Marketing;

use App\Domain\Catalog\Models\Product;
use App\Domain\Marketing\Models\ProductQuestion;
use App\Domain\Marketing\Models\Review;
use App\Domain\Marketing\Services\ReviewService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Review moderation (approve / reject / hide with a note) and product Q&A answers. */
class ReviewIndex extends Component
{
    use WithAdminTable;

    #[Url(except: 'reviews')]
    public string $tab = 'reviews';

    #[Url(except: 'pending')]
    public string $filterStatus = 'pending';

    #[Url(except: '')]
    public string $filterRating = '';

    /** id => moderation note / answer draft */
    public array $notes = [];

    public array $answers = [];

    public function mount(): void
    {
        abort_unless($this->can('reviews.view'), 403);
    }

    public function updatedTab(): void
    {
        $this->filterStatus = 'pending';
        $this->resetPage();
    }

    public function moderate(string $id, string $status, ReviewService $svc): void
    {
        abort_unless($this->canGlobally('reviews.approve') && in_array($status, ['approved', 'rejected', 'hidden'], true), 403);
        $note = trim((string) ($this->notes[$id] ?? '')) ?: null;
        if ($status !== 'approved' && ! $note) {
            $this->addError('notes.'.$id, __('marketing_admin.note_required'));

            return;
        }
        $this->attempt(fn () => $svc->moderate(Review::findOrFail($id), auth()->user(), $status, $note), __('marketing_admin.review_'.$status));
        unset($this->notes[$id]);
    }

    public function answer(string $id, string $status, ReviewService $svc): void
    {
        abort_unless($this->canGlobally('reviews.approve') && in_array($status, ['published', 'rejected'], true), 403);
        $this->validate(['answers.'.$id => ($status === 'published' ? 'required' : 'nullable').'|string|max:2000'], [], ['answers.'.$id => __('marketing_admin.answer')]);
        if ($this->attempt(fn () => $svc->answerQuestion(ProductQuestion::findOrFail($id), auth()->user(), $this->answers[$id] ?? null, $status), $status === 'published' ? __('marketing_admin.answer_published') : __('marketing_admin.question_rejected'))) {
            unset($this->answers[$id]);
        }
    }

    public function render()
    {
        $s = trim($this->search);
        if ($this->tab === 'questions') {
            $rows = ProductQuestion::query()->with(['user:id,name'])->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
                ->when($s, fn ($q) => $q->where('question', 'ilike', "%{$s}%"))->latest()->paginate($this->perPage);
            foreach ($rows as $q) {
                $this->answers[$q->id] ??= (string) $q->answer;
            }
        } else {
            $rows = Review::query()->with(['user:id,name', 'product.translations', 'media'])
                ->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
                ->when($this->filterRating, fn ($q, $r) => $q->where('rating', (int) $r))
                ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('title', 'ilike', "%{$s}%")->orWhere('body', 'ilike', "%{$s}%")))
                ->orderByDesc('report_count')->latest()->paginate($this->perPage);
        }
        $products = $this->tab === 'questions' ? Product::with('translations')->whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id') : collect();

        return view('livewire.admin.marketing.reviews', [
            'rows' => $rows,
            'products' => $products,
            'pending' => ['reviews' => Review::where('status', 'pending')->count(), 'questions' => ProductQuestion::where('status', 'pending')->count()],
            'canModerate' => $this->canGlobally('reviews.approve'),
        ])->title(__('nav.reviews'));
    }
}
