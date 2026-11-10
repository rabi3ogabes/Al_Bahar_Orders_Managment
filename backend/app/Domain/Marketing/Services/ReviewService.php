<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\ProductQuestion;
use App\Domain\Marketing\Models\Review;
use App\Domain\Orders\Models\OrderItem;
use App\Support\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reviews: one per customer per product, verified-purchase flag set server-side, moderated before publishing. */
class ReviewService
{
    public function submit(User $user, Product $product, int $rating, ?string $title, ?string $body, array $photos = []): Review
    {
        if (Review::where('product_id', $product->id)->where('user_id', $user->id)->exists()) {
            throw DomainException::make('review_exists', 409);
        }
        $purchase = OrderItem::where('product_id', $product->id)->where('quantity_handed_over', '>', 0)
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id))->latest()->first();

        return DB::transaction(function () use ($user, $product, $rating, $title, $body, $photos, $purchase) {
            $review = Review::create(['product_id' => $product->id, 'user_id' => $user->id, 'order_item_id' => $purchase?->id, 'rating' => $rating,
                'title' => $title ? strip_tags($title) : null, 'body' => $body ? strip_tags($body) : null, 'locale' => app()->getLocale(),
                'is_verified_purchase' => $purchase !== null, 'status' => 'pending']);
            foreach (array_slice($photos, 0, 4) as $photo) {
                /** @var UploadedFile $photo */
                if (in_array($photo->getMimeType(), config('platform.media.image_mimes'), true) && $photo->getSize() <= 8 * 1024 * 1024) {
                    $review->media()->create(['disk' => config('platform.media.disk'), 'path' => $photo->store('reviews/'.$review->id, config('platform.media.disk'))]);
                }
            }

            return $review;
        });
    }

    public function moderate(Review $review, User $staff, string $status, ?string $note = null): Review
    {
        $review->update(['status' => $status, 'moderated_by' => $staff->id, 'moderated_at' => now(), 'moderation_note' => $note]);
        $this->recalculate($review->product_id);
        app(AuditLogger::class)->log('review.moderated', $review, [], ['status' => $status, 'note' => $note], actor: $staff);

        return $review;
    }

    /** Answer and/or publish/reject a product question (Q&A). Answers are plain text. */
    public function answerQuestion(ProductQuestion $question, User $staff, ?string $answer, string $status): ProductQuestion
    {
        if (! in_array($status, ['pending', 'published', 'rejected'], true) || ($status === 'published' && trim((string) ($answer ?? $question->answer)) === '')) {
            throw DomainException::make('validation_failed', 422);
        }
        $before = $question->only(['status', 'answer']);
        $answer = $answer !== null ? trim(strip_tags($answer)) : $question->answer;
        $question->update(['answer' => $answer ?: null, 'status' => $status] + ($answer && $answer !== $before['answer'] ? ['answered_by' => $staff->id, 'answered_at' => now()] : []));
        app(AuditLogger::class)->log('review.question_answered', $question, $before, $question->only(['status', 'answer']), actor: $staff);

        return $question;
    }

    public function report(Review $review, User $user, string $reason): void
    {
        $inserted = DB::table('review_reports')->insertOrIgnore(['id' => (string) Str::uuid(), 'review_id' => $review->id, 'user_id' => $user->id,
            'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
        if ($inserted) {
            $review->increment('report_count');
            if ($review->fresh()->report_count >= 5 && $review->status === 'approved') {
                $review->update(['status' => 'hidden', 'moderation_note' => 'Auto-hidden after 5 reports — awaiting review']);
                $this->recalculate($review->product_id);
            }
        }
    }

    public function recalculate(string $productId): void
    {
        $agg = Review::where('product_id', $productId)->where('status', 'approved')->selectRaw('count(*) as c, coalesce(avg(rating),0) as a')->toBase()->first();
        Product::whereKey($productId)->update(['rating_count' => (int) $agg->c, 'rating_average' => round((float) $agg->a, 2)]);
    }
}
