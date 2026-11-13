export interface Money { amount: string; currency: string; formatted: string }
export interface Price { regular: Money; sale: Money | null; final: Money; discount_percent: number; sale_ends_at: string | null; source: string }
export interface Img { url: string; thumb_url: string; alt: string; variant_id?: string | null }
export interface Paged<T> { data: T[]; meta: { current_page: number; per_page?: number; total: number; last_page: number } }

export interface ProductCard {
  id: string; slug: string; sku: string; name: string; short_description: string | null
  brand: { id: string; name: string } | null; image: Img | null; price: Price
  rating: { average: number; count: number }; badges: string[]; in_stock: boolean; purchasable: boolean; has_variants: boolean
  variant_id?: string | null // the single variant a card can add straight to the bag
}
export interface Variant {
  id: string; sku: string; name: string; attribute_value_ids: number[]; price: Price
  available_in_branch: boolean; in_stock: boolean; available_quantity: number; image_url: string | null
}
export interface AttributeDef { code: string; name: string; type: 'color' | 'select' | string; values: { id: number; code: string; name: string; color_hex: string | null }[] }
export interface ProductDetail extends ProductCard {
  description: string | null; material: string | null; category: { id: string; slug: string; name: string } | null
  tags: string[]; specifications: { label: string; value: string }[]
  dimensions: { length: number; width: number; height: number; unit: string } | null
  weight: { value: number; unit: string } | null
  warranty: { duration_months: number; provider: string | null; terms: string | null } | null
  requires_assembly: boolean; assembly_service_available: boolean; is_oversized: boolean
  images: Img[]; videos: { url: string; source: string; title: string }[]
  attributes: AttributeDef[]; variants: Variant[]; default_variant_id: string | null
  availability: { branch_id: string | null; purchasable: boolean; reason: string | null }
  related: ProductCard[]; upsell?: ProductCard[]; share_url: string; seo: { title: string | null; description: string | null }
}
export interface Category { id: string; slug: string; name: string; description: string | null; image_url: string | null; icon_url: string | null; banner_url: string | null; children: Category[] }
export interface Brand { id: string; slug: string; name: string; logo_url: string | null }

export interface Branch {
  id: string; code: string; name: string; description: string | null; phone: string | null; email: string | null
  address: string | null; area: string | null; zone_number: string | null; latitude: number | null; longitude: number | null
  pickup_enabled: boolean; delivery_enabled: boolean; cod_enabled: boolean; pay_at_pickup_enabled: boolean
  opening: { is_open: boolean; reason: string; closes_at: string | null; next_open_at: string | null }
}
export interface BranchHours { timezone: string; weekly: { day_of_week: number; is_closed: boolean; opens_at: string | null; closes_at: string | null; break_starts_at: string | null; break_ends_at: string | null }[]; holidays: { date: string; name?: string }[] }
export interface Slot { id: string; date: string; starts_at: string; ends_at: string; capacity: number; available: number; is_full: boolean }
export interface SlotDay { date: string; available: number; slots: Slot[] }

export interface HomeSection { id: string; type: string; layout: string; title: string | null; subtitle: string | null; items: any[]; [k: string]: unknown }

export interface User {
  id: string; type: string; name: string; phone: string; email: string | null; phone_verified: boolean; locale: 'en' | 'ar'
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
}
export interface Cart {
  id: string; branch_id: string | null; fulfillment_type: 'delivery' | 'pickup'; address_id: string | null; coupon_code: string | null
  loyalty_points: number; items: CartItem[]; items_count: number; subtotal: Money; warnings: { code?: string; message?: string }[]
}

export interface QuoteLine { variant_id: string; product_id: string; branch_id: string; name: string; variant_name: string | null; image_url: string | null; quantity: number; unit_price: Price; line_total: Money }
export interface Quote {
  quote_hash: string; currency: string; fulfillment_type: 'delivery' | 'pickup'; payment_method: string | null; is_split: boolean
  fulfillments: { branch: { id: string; name: string; area: string | null }; type: string; items: QuoteLine[]; subtotal: Money; delivery_fee: Money; eta_minutes: number | null; slot: Slot | null; slot_required: boolean; allocation_reason: string }[]
  lines: QuoteLine[]
  totals: { subtotal: Money; discount: Money; delivery_fee: Money; service_fee: Money; cod_fee: Money; tax: Money; loyalty_discount: Money; grand_total: Money }
  discounts: { code?: string; label?: string; amount?: Money }[]
  payment_methods: { code: 'sadad' | 'cod' | 'pay_at_pickup'; available: boolean; reason: string | null }[]
  loyalty: { balance: number; max_redeemable_points: number; points_applied: number; points_to_earn?: number }
  warnings?: { code?: string; message?: string }[]
}

export interface OrderSummary {
  id: string; number: string; status: string; status_label: string; payment_status: string; payment_method: string; fulfillment_type: string
  items_count: number; grand_total: Money; placed_at: string; thumbnail_url: string | null
}
export interface OrderItem { id: string; product_id: string; variant_id: string; sku: string; name: string; variant_name: string | null; image_url: string | null; quantity: number; unit_price: Money; line_total: Money }
export interface OrderDetail extends OrderSummary {
  totals: Record<string, Money>; discounts: unknown[]; address: Record<string, string | null> | null; customer_notes: string | null
  items: OrderItem[]
  fulfillments: { id: string; number: string; type: string; status: string; status_label: string; branch: { id: string; name: string; phone: string | null; latitude: number | null; longitude: number | null }; subtotal: Money; delivery_fee: Money; slot: Slot | null; delivery: Record<string, unknown> | null; pickup: { status: string; token_expires_at: string | null } | null; timestamps: Record<string, string | null> }[]
  can_cancel: boolean; can_pay?: boolean; can_return?: boolean; payment_due_at?: string | null
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
