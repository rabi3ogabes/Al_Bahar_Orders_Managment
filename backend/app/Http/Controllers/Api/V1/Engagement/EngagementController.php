<?php

namespace App\Http\Controllers\Api\V1\Engagement;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\StockAlert;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Services\HomepageService;
use App\Domain\Marketing\Models\LoyaltyTransaction;
use App\Domain\Marketing\Models\ProductQuestion;
use App\Domain\Marketing\Models\Review;
use App\Domain\Marketing\Models\Wishlist;
use App\Domain\Marketing\Models\WishlistItem;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Marketing\Services\ReferralService;
use App\Domain\Marketing\Services\ReviewService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Pricing\Services\PriceResolver;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Services\SupportService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @tags Engagement */
class EngagementController extends ApiController
{
    public function home(Request $request, HomepageService $home): JsonResponse
    {
        $branchId = $request->validate(['branch_id' => 'nullable|uuid'])['branch_id'] ?? null;
        $branch = $branchId ? Branch::active()->find($branchId) : null;
        $key = 'home:'.app()->getLocale().':'.($branch?->id ?? 'all');

        // Short cache: content is admin-managed and prices must stay fresh.
        return response()->json(['data' => Cache::remember($key, 60, fn () => $home->render($branch))]);
    }

    public function page(string $slug): JsonResponse
    {
        $page = CmsPage::with('translations')->where('slug', $slug)->where('status', 'published')->firstOrFail();
        $t = $page->translations->firstWhere('locale', app()->getLocale()) ?? $page->translations->firstWhere('locale', 'en');

        return response()->json(['data' => ['slug' => $page->slug, 'title' => $t?->title, 'body' => $t?->body, 'updated_at' => BusinessTime::iso($page->updated_at)]]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $q = Notification::where('user_id', $this->user()->id)->latest();
        $page = $q->paginate($this->perPage($request));

        return response()->json(['data' => $page->getCollection()->map(fn ($n) => ['id' => $n->id, 'event' => $n->event, 'title' => $n->title, 'body' => $n->body,
            'data' => $n->data, 'read_at' => BusinessTime::iso($n->read_at), 'created_at' => BusinessTime::iso($n->created_at)]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'unread_count' => Notification::where('user_id', $this->user()->id)->whereNull('read_at')->count()]]);
    }

    public function readNotification(string $id): JsonResponse
    {
        Notification::where('user_id', $this->user()->id)->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['read' => true]]);
    }

    public function readAll(): JsonResponse
    {
        Notification::where('user_id', $this->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['read' => true]]);
    }

    public function preferences(NotificationService $n): JsonResponse
    {
        return response()->json(['data' => $n->preferences($this->user())]);
    }

    public function updatePreferences(Request $request, NotificationService $n): JsonResponse
    {
        $data = $request->validate(['preferences' => 'required|array', 'preferences.*.channel' => 'required|in:push,sms,whatsapp,email,in_app',
            'preferences.*.category' => 'required|in:transactional,marketing,auction', 'preferences.*.is_enabled' => 'required|boolean']);
        $n->setPreferences($this->user(), $data['preferences']);

        return response()->json(['data' => $n->preferences($this->user())]);
    }

    public function loyalty(LoyaltyService $loyalty): JsonResponse
    {
        $account = $loyalty->account($this->user());

        return response()->json(['data' => ['balance' => $account->balance, 'tier' => $account->tier, 'lifetime_earned' => $account->lifetime_earned,
            'value' => $loyalty->valueOf($account->balance)->jsonSerialize(),
            'transactions' => LoyaltyTransaction::where('loyalty_account_id', $account->id)->orderByDesc('id')->limit(50)->get()->map(fn ($t) => [
                'type' => $t->type, 'points' => $t->points, 'balance_after' => $t->balance_after, 'reason' => $t->reason,
                'created_at' => BusinessTime::iso($t->created_at), 'expires_at' => BusinessTime::iso($t->expires_at)])]]);
    }

    public function referral(ReferralService $referrals): JsonResponse
    {
        return response()->json(['data' => $referrals->summary($this->user())]);
    }

    public function wishlist(Request $request, CatalogPresenter $catalog): JsonResponse
    {
        $list = Wishlist::firstOrCreate(['user_id' => $this->user()->id, 'name' => 'Default']);
        $items = WishlistItem::where('wishlist_id', $list->id)->with(['product' => fn ($q) => $q->with(['translations', 'brand', 'images', 'variants' => fn ($v) => $v->where('status', 'active')])])->latest()->get()
            ->filter(fn ($i) => $i->product !== null);
        $branch = $request->query('branch_id') ? Branch::active()->find($request->query('branch_id')) : null;
        $cards = collect($catalog->cards($items->pluck('product'), $branch))->keyBy('id');

        return response()->json(['data' => $items->map(fn ($i) => ['id' => $i->id, 'product' => $cards[$i->product_id] ?? null, 'variant_id' => $i->product_variant_id,
            'added_at' => BusinessTime::iso($i->created_at)])->values()]);
    }

    public function addWishlist(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => 'required|uuid|exists:products,id', 'variant_id' => 'nullable|uuid|exists:product_variants,id']);
        $list = Wishlist::firstOrCreate(['user_id' => $this->user()->id, 'name' => 'Default']);
        WishlistItem::firstOrCreate(['wishlist_id' => $list->id, 'product_id' => $data['product_id'], 'product_variant_id' => $data['variant_id'] ?? null]);

        return response()->json(['data' => ['added' => true]], 201);
    }

    public function removeWishlist(string $productId): JsonResponse
    {
        $list = Wishlist::where('user_id', $this->user()->id)->first();
        $list && WishlistItem::where('wishlist_id', $list->id)->where('product_id', $productId)->delete();

        return response()->json(['data' => ['removed' => true]]);
    }

    public function reviews(Request $request, string $productId): JsonResponse
    {
        $page = Review::where('product_id', $productId)->where('status', 'approved')->with(['user:id,name', 'media'])->latest()->paginate($this->perPage($request));

        return response()->json(['data' => $page->getCollection()->map(fn ($r) => ['id' => $r->id, 'rating' => $r->rating, 'title' => $r->title, 'body' => $r->body,
            'author' => Str::before($r->user?->name ?? '', ' '), 'verified_purchase' => $r->is_verified_purchase, 'created_at' => BusinessTime::iso($r->created_at),
            'photos' => $r->media->map(fn ($m) => Storage::disk($m->disk)->url($m->path))]),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function submitReview(Request $request, string $productId, ReviewService $reviews): JsonResponse
    {
        $data = $request->validate(['rating' => 'required|integer|between:1,5', 'title' => 'nullable|string|max:120', 'body' => 'nullable|string|max:3000',
            'photos' => 'nullable|array|max:4', 'photos.*' => 'image|max:8192']);
        $review = $reviews->submit($this->user(), Product::active()->findOrFail($productId), (int) $data['rating'], $data['title'] ?? null, $data['body'] ?? null, $request->file('photos', []));

        return response()->json(['data' => ['id' => $review->id, 'status' => $review->status, 'verified_purchase' => $review->is_verified_purchase]], 201);
    }

    public function reportReview(Request $request, string $id, ReviewService $reviews): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|in:spam,offensive,irrelevant,fake,other']);
        $reviews->report(Review::where('status', 'approved')->findOrFail($id), $this->user(), $data['reason']);

        return response()->json(['data' => ['reported' => true]]);
    }

    public function questions(string $productId): JsonResponse
    {
        return response()->json(['data' => ProductQuestion::where('product_id', $productId)->where('status', 'published')->latest()->limit(50)->get()
            ->map(fn ($q) => ['id' => $q->id, 'question' => $q->question, 'answer' => $q->answer, 'answered_at' => BusinessTime::iso($q->answered_at)])]);
    }

    public function ask(Request $request, string $productId): JsonResponse
    {
        $data = $request->validate(['question' => 'required|string|min:5|max:1000']);
        $q = ProductQuestion::create(['product_id' => Product::active()->findOrFail($productId)->id, 'user_id' => $this->user()->id, 'question' => strip_tags($data['question'])]);

        return response()->json(['data' => ['id' => $q->id, 'status' => $q->status ?? 'pending']], 201);
    }

    public function addAlert(Request $request, PriceResolver $prices): JsonResponse
    {
        $data = $request->validate(['variant_id' => 'required|uuid|exists:product_variants,id', 'type' => 'required|in:back_in_stock,price_drop', 'branch_id' => 'nullable|uuid|exists:branches,id']);
        $variant = ProductVariant::with('product')->findOrFail($data['variant_id']);
        $ref = $data['type'] === 'price_drop' ? $prices->resolve($variant, isset($data['branch_id']) ? Branch::find($data['branch_id']) : null)?->final()->toDecimal() : null;
        StockAlert::updateOrCreate(['user_id' => $this->user()->id, 'product_variant_id' => $variant->id, 'type' => $data['type']],
            ['branch_id' => $data['branch_id'] ?? null, 'reference_price' => $ref, 'notified_at' => null]);

        return response()->json(['data' => ['subscribed' => true]], 201);
    }

    public function removeAlert(Request $request): JsonResponse
    {
        $data = $request->validate(['variant_id' => 'required|uuid', 'type' => 'required|in:back_in_stock,price_drop']);
        StockAlert::where('user_id', $this->user()->id)->where('product_variant_id', $data['variant_id'])->where('type', $data['type'])->delete();

        return response()->json(['data' => ['subscribed' => false]]);
    }

    public function tickets(): JsonResponse
    {
        return response()->json(['data' => SupportTicket::where('user_id', $this->user()->id)->latest()->limit(100)->get()
            ->map(fn ($t) => ['id' => $t->id, 'number' => $t->number, 'subject' => $t->subject, 'category' => $t->category, 'status' => $t->status,
                'status_label' => __('status.'.$t->status), 'updated_at' => BusinessTime::iso($t->updated_at)])]);
    }

    public function openTicket(Request $request, SupportService $support): JsonResponse
    {
        $data = $request->validate(['category' => 'required|in:order,delivery,payment,product,auction,return,account,other', 'subject' => 'required|string|max:191',
            'message' => 'required|string|max:5000', 'order_id' => 'nullable|uuid']);
        $t = $support->open($this->user(), $data);

        return response()->json(['data' => ['id' => $t->id, 'number' => $t->number, 'status' => $t->status]], 201);
    }

    public function ticket(string $id): JsonResponse
    {
        $t = SupportTicket::where('user_id', $this->user()->id)->with(['messages' => fn ($q) => $q->where('is_internal', false)])->findOrFail($id);

        return response()->json(['data' => ['id' => $t->id, 'number' => $t->number, 'subject' => $t->subject, 'category' => $t->category, 'status' => $t->status,
            'messages' => $t->messages->map(fn ($m) => ['id' => $m->id, 'from' => $m->author_type, 'body' => $m->body, 'created_at' => BusinessTime::iso($m->created_at)])]]);
    }

    public function replyTicket(Request $request, string $id, SupportService $support): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:5000']);
        $support->customerReply(SupportTicket::where('user_id', $this->user()->id)->findOrFail($id), $this->user(), $data['body']);

        return $this->ticket($id);
    }
}
