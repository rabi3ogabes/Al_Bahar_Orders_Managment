import type { ReturnClaim } from './returns';
import type { SubstitutionRequest } from './substitutions';
import type { AssemblyAnswer, DeliveryProof, SlotOffer } from './followUps';
import type { DealBlock } from './deals';
import type { BasketPromise, PromiseParcel } from './promise';
export interface Money { amount: string; currency: string; formatted: string }
/** Money as the server may send it: the usual object, or (for the extra delivery fee) a bare decimal such as "50.00". Read with `parseMoney` (lib/deliveryFee.ts). */
export type MoneyLike = Money | string | number
/** How a product's extra delivery fee is charged: for every unit in the order, or once for the product whatever the quantity. */
export type ExtraDeliveryMode = 'per_unit' | 'per_line'
export interface Price { regular: Money; sale: Money | null; final: Money; discount_percent: number; sale_ends_at: string | null; source: string }
export interface Img { url: string; thumb_url: string; alt: string; variant_id?: string | null }
export interface Paged<T> { data: T[]; meta: { current_page: number; per_page?: number; total: number; last_page: number } }

export interface ProductCard {
  id: string; slug: string; sku: string; name: string; short_description: string | null
  brand: { id: string; name: string } | null; image: Img | null; price: Price
  rating: { average: number; count: number }; badges: string[]; in_stock: boolean; purchasable: boolean; has_variants: boolean
  variant_id?: string | null // the single variant a card can add straight to the bag
  /** Extra delivery fee of bulky products (docs/api/CHECKOUT_FEES_V2.md, section 3). Missing or 0 means none; absent from an older server. */
  extra_delivery_fee?: MoneyLike | null
  extra_delivery_fee_mode?: ExtraDeliveryMode | null
  /** Assembly and installation at the door: the fee per unit ("75.00") when the product offers it, otherwise null or absent (also from an older server). */
  assembly_fee?: MoneyLike | null
  /** The countdown offer this product is in (docs/api/DEALS.md); null or absent when none, and always absent from an older server. */
  deal?: DealBlock | null
}
export interface Variant {
  id: string; sku: string; name: string; attribute_value_ids: number[]; price: Price
  available_in_branch: boolean; in_stock: boolean; available_quantity: number; image_url: string | null
  /** This variant's own deal price while the product's deal is live (null: the variant has no deal). Read with `dealPriceFor`. */
  deal_price?: Money | null
}
export interface AttributeDef { code: string; name: string; type: 'color' | 'select' | string; values: { id: number; code: string; name: string; color_hex: string | null }[] }
export interface ProductDetail extends ProductCard {
  description: string | null; material: string | null; category: { id: string; slug: string; name: string } | null
  tags: string[]; specifications: { label: string; value: string }[]
  dimensions: { length: number; width: number; height: number; unit: string } | null
  weight: { value: number; unit: string } | null
  warranty: { duration_months: number; provider: string | null; terms: string | null } | null
  requires_assembly: boolean; assembly_service_available: boolean; is_oversized: boolean; /** Days the branch needs before it can leave (made to order); 0 = ready now. */ lead_time_days?: number
  images: Img[]; videos: { url: string; source: string; title: string }[]
  attributes: AttributeDef[]; variants: Variant[]; default_variant_id: string | null
  availability: { branch_id: string | null; purchasable: boolean; reason: string | null }
  related: ProductCard[]; upsell?: ProductCard[]; share_url: string; seo: { title: string | null; description: string | null }
}
export interface Category { id: string; slug: string; name: string; description: string | null; image_url: string | null; icon_url: string | null; banner_url: string | null; children: Category[] }
/** One attribute a shopper can filter by (colour, material, size...) with its values: `GET /categories/{id}` → `filters`. `color_hex` is set for colours. */
export interface AttributeFilterGroup { attribute: { code: string; name: string; type: string }; values: { id: number; code: string; name: string; color_hex: string | null }[] }
export interface Brand { id: string; slug: string; name: string; logo_url: string | null }

export interface Branch {
  id: string; code: string; name: string; description: string | null; phone: string | null; email: string | null
  address: string | null; area: string | null; zone_number: string | null; latitude: number | null; longitude: number | null
  pickup_enabled: boolean; delivery_enabled: boolean; cod_enabled: boolean; pay_at_pickup_enabled: boolean
  opening: { is_open: boolean; reason: string; closes_at: string | null; next_open_at: string | null }
}
export interface BranchHours { timezone: string; weekly: { day_of_week: number; is_closed: boolean; opens_at: string | null; closes_at: string | null; break_starts_at: string | null; break_ends_at: string | null }[]; holidays: { date: string; name?: string }[] }
export interface Slot { id: string; date: string; starts_at: string; ends_at: string; capacity: number; available: number; is_full: boolean; /** Last moment this time can be booked (its start minus the cutoff); absent from an older server. */ bookable_until?: string | null }
export interface SlotDay { date: string; available: number; slots: Slot[] }

export interface HomeSection { id: string; type: string; layout: string; title: string | null; subtitle: string | null; items: any[]; slider?: Partial<import('./sliderCore').SliderSettings>; [k: string]: unknown }
/** One slide of a home slider (`GET /home`, section type `banners`). `link` is null when the slide is not clickable. */
export interface HomeSlide { id: string; title: string | null; subtitle: string | null; image_url: string | null; link: { type: string; value: string } | null }

export interface User {
  id: string; type: string; name: string; phone: string; /** Their saved second number (E.164) for the delivery driver. */ alternate_phone?: string | null; email: string | null; phone_verified: boolean; locale: 'en' | 'ar'
  avatar_url: string | null; date_of_birth: string | null; gender: string | null; referral_code: string; marketing_consent: boolean; has_password: boolean; created_at: string
}
export interface Tokens { access_token: string; refresh_token: string; token_type: string; expires_in: number; session_id: string }
export interface AuthResult { status: 'authenticated' | 'registration_required'; user?: User; tokens?: Tokens; registration_token?: string; phone?: string }

export interface Address {
  id: string; label: string | null; recipient_name: string; recipient_phone: string; delivery_zone: { id: string; name: string } | null
  zone_number: string; street_number: string; building_number: string; unit_number: string | null; area: string | null; city: string | null
  landmark: string | null; instructions: string | null; latitude: number | null; longitude: number | null; is_default: boolean; summary: string
}
export interface DeliveryZone { id: string; code: string; name: string; zone_numbers: string[] }

export interface CartItem {
  id: string; variant_id: string; product: ProductCard; variant_name: string | null; quantity: number; unit_price: Price; line_total: Money
  available: boolean; available_quantity: number; price_changed: boolean; previous_price: Money | null; issue: string | null
  /** Assembly and installation: null when the product offers none; `amount` is what the line adds (null while unticked, or on a pickup cart). */
  assembly?: { selected: boolean; fee_each: Money; amount: Money | null } | null
  /** The product's deal block plus `units_at_deal_price`, `units_at_regular_price` and `note` for this line (the checkout quote is the final word). */
  deal?: DealBlock | null
}
export interface Cart {
  id: string; branch_id: string | null; fulfillment_type: 'delivery' | 'pickup'; address_id: string | null; coupon_code: string | null
  loyalty_points: number; items: CartItem[]; items_count: number; subtotal: Money; warnings: { code?: string; message?: string }[]
  /** What the deals will take off at checkout (the subtotal is at regular prices); absent from an older server. */
  deal_savings?: Money | null
  /** What the lines with assembly add to the total on delivery ("0.00" when none); the subtotal stays goods only. Absent from an older server. */
  assembly_total?: Money | null
}

/** One product's share of the extra delivery fee (`delivery_extras` of a quote). */
export interface DeliveryExtra { product_id?: string; name?: string; mode?: ExtraDeliveryMode | null; quantity?: number; fee_each?: MoneyLike | null; amount?: MoneyLike | null }
export interface QuoteLine { variant_id: string; product_id: string; branch_id: string; name: string; variant_name: string | null; image_url: string | null; quantity: number; unit_price: Price; line_total: Money
  /** The assembly chosen for this line (fee per unit and what the line owes), or null. */
  assembly?: { fee_each: Money; amount: Money } | null
  /** `{ deal_id, name, units_at_deal_price, units_at_regular_price, savings, ends_at, note }` or null. Read with `readQuoteDeal`. */
  deal?: unknown
}
export interface Quote {
  quote_hash: string; currency: string; fulfillment_type: 'delivery' | 'pickup'; payment_method: string | null; is_split: boolean
  /** The "allow split shipments" switch is worth showing: the admin setting is on AND more than one active branch holds part of the cart. */
  split_choice?: boolean
  errors?: { code?: string; message?: string }[]; can_place_order?: boolean
  fulfillments: { branch: { id: string; name: string; area: string | null }; type: string; items: QuoteLine[]; subtotal: Money; delivery_fee: Money; eta_minutes: number | null; slot: Slot | null; slot_required: boolean; allocation_reason: string
    /** The first day these items can be scheduled on (their lead time); the time picker starts there. Absent from an older server. */
    earliest_date?: string | null; promise?: PromiseParcel | null }[]
  /** The dates promised for the basket; null when the dashboard switch is off, absent from an older server. */
  promise?: BasketPromise | null
  lines: QuoteLine[]
  /** `delivery_fee` is the gross delivery total: it already holds `extra_delivery_fee` (the part that comes from bulky products). Both extra fields are absent from an older server. */
  totals: { subtotal: Money; discount: Money; delivery_fee: Money; extra_delivery_fee?: MoneyLike | null; /** Assembly and installation ("0.00" when none); inside the grand total. */ assembly_fee?: Money | null; service_fee: Money; tax: Money; loyalty_discount: Money; grand_total: Money
    /** What the deals took off ("0.00" when none). Absent from an older server. */
    deal_savings?: Money | null }
  /** What makes up `totals.extra_delivery_fee`, one row per product; empty or missing when there is none. */
  delivery_extras?: DeliveryExtra[] | null
  /** `free_delivery`: this discount is a free-delivery coupon (it waives the delivery fee; its amount counts that fee). */
  discounts: { code?: string; label?: string; amount?: Money; free_delivery?: boolean; /** `deal` for a countdown offer. */ source?: string }[]
  payment_methods: { code: 'sadad' | 'cod' | 'pay_at_pickup'; available: boolean; reason: string | null; /** Cash on delivery, when `reason` is `outside_cod_limits`: the lowest / highest basket total it takes (absent from an older server). */ limits?: { min?: Money | null; max?: Money | null } | null }[]
  loyalty: { balance: number; max_redeemable_points: number; points_applied: number; points_to_earn?: number }
  warnings?: { code?: string; message?: string }[]
}

export interface OrderSummary {
  id: string; number: string; status: string; status_label: string; payment_status: string; payment_method: string; fulfillment_type: string
  items_count: number; grand_total: Money; placed_at: string; thumbnail_url: string | null
}
export interface OrderItem { id: string; product_id: string; variant_id: string; sku: string; name: string; variant_name: string | null; image_url: string | null; quantity: number; quantity_cancelled?: number; quantity_returned?: number; /** How many of this line can still be asked back right now (an older server leaves it out). */ returnable_quantity?: number; fulfillment_id?: string | null; unit_price: Money; line_total: Money; adjustment?: ItemAdjustment | null
  /** Assembly and installation bought for this line (fee per unit as placed, and what the line owes), or null. */
  assembly?: { fee_each: Money; amount: Money } | null
  /** The deal this line got (`{ deal_id, name, units, unit_savings }`), a snapshot; read with `orderLineDeal`. */
  deal?: unknown
}
/** What the branch did to one line while preparing the order (see docs/api/ORDER_ADJUSTMENTS.md). */
export interface ItemAdjustment { kind: 'reduced' | 'removed' | 'replaced' | 'replacement'; original_quantity: number; original_name?: string | null; reason_code?: string | null; reason_label?: string | null; replaces_name?: string | null; replaced_by_name?: string | null }
export interface OrderRevision { number: number; at: string; reason_label?: string | null; note?: string | null; branch?: string | null; lines?: { text?: string | null }[]; total_before?: Money | null; total_after?: Money | null; order_cancelled?: boolean }
/** `fulfillments[].delivery`: who is bringing it. `driver_phone` is sent only while the driver has the order (never after delivery, never for pickup). */
export interface OrderDelivery { tracking_number?: string | null; status?: string; status_label?: string; provider?: string | null; driver_name?: string | null; driver_phone?: string | null; eta?: string | null }
export interface OrderDetail extends OrderSummary {
  totals: Record<string, Money>; discounts: unknown[]; address: Record<string, string | null> | null; customer_notes: string | null
  items: OrderItem[]
  fulfillments: { id: string; number: string; type: string; status: string; status_label: string; branch: { id: string; name: string; phone: string | null; latitude: number | null; longitude: number | null }; subtotal: Money; delivery_fee: Money; slot: Slot | null; delivery: OrderDelivery | null; pickup: { status: string; token_expires_at: string | null } | null; timestamps: Record<string, string | null>; /** New times offered after a failed delivery (null: none, or it does not apply any more). */ slot_offer?: SlotOffer | null; /** When it was delivered, who received it and the photo (null until delivered). */ proof?: DeliveryProof | null; /** The driver's answer for paid assembly (null: none was bought). */ assembly?: AssemblyAnswer | null }[]
  can_cancel: boolean; can_pay?: boolean; can_return?: boolean; /** The latest return request of this order (null: none yet; absent from an older server). */ /** Replacements the branch offered that the customer is asked about (newest first), and whether one still waits for their answer. */ substitution_requests?: SubstitutionRequest[]; waiting_for_customer?: boolean; return_request?: ReturnClaim | null; /** Every claim of this order, newest first. */ returns?: ReturnClaim[]; /** The last day a problem can be reported (null: nothing can be reported now). */ return_window?: { days: number; until: string | null }; payment_due_at?: string | null; was_adjusted?: boolean; revisions?: OrderRevision[]
  [k: string]: unknown
}
export interface TimelineEvent { kind: string; status: string; label: string; fulfillment_id: string | null; at: string }
export interface PaymentAction { payment_id: string; method: string; status: string; action: 'none' | 'redirect'; redirect_url: string | null; expires_at: string | null }

export interface Review { id: string; rating: number; title: string | null; body: string | null; author: string; verified_purchase: boolean; created_at: string; photos: string[] }

export interface Auction { id: string; reference: string; type: string; status: string; title: string; cover_image_url: string | null; starts_at: string; ends_at: string; lots_count: number; deposit: Money | null }
export interface Lot {
  id: string; auction_id: string; lot_number: number; title: string; description: string | null; image_url: string | null; quantity: number; status: string
  starting_price: Money; current_price: Money; min_next_bid: Money; min_increment: Money; buy_now_price: Money | null; reserve_met: boolean
  bid_count: number; last_sequence: number; starts_at: string; ends_at: string; extension_count: number; server_time: string
  my_status: string | null; my_max_auto_bid: Money | null; is_watching: boolean
}
export interface AuctionDetail extends Auction {
  description: string | null; terms: string | null; requires_registration: boolean; requires_verified_phone: boolean; winner_payment_hours: number
  cod_allowed: boolean; pickup_allowed: boolean; delivery_allowed: boolean; my_registration: unknown; lots: Lot[]; server_time: string
}
export interface Loyalty { balance: number; tier: string; lifetime_earned: number; value: Money; transactions: { type: string; points: number; balance_after: number; reason: string | null; created_at: string; expires_at: string | null }[] }
export interface CmsPage { slug: string; title: string; body: string; updated_at: string }
export interface Ticket { id: string; subject?: string; status: string; created_at: string; [k: string]: unknown }

/** `GET /me/referral`: only `enabled: false` is sent while the programme is off. */
export interface Referral {
  enabled: boolean; code?: string; share_url: string; referred_count: number
  referrer_reward?: { type: string; text: string | null } | null
}
