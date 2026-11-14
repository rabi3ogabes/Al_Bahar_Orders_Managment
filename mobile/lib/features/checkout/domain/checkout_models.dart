import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

enum FulfillmentType {
  delivery('delivery'),
  pickup('pickup');

  const FulfillmentType(this.api);
  final String api;

  static FulfillmentType? parse(String? v) => values.where((e) => e.api == v).firstOrNull;
}

enum PaymentMethod {
  sadad('sadad'),
  cod('cod'),
  payAtPickup('pay_at_pickup');

  const PaymentMethod(this.api);
  final String api;

  static PaymentMethod? parse(String? v) => values.where((e) => e.api == v).firstOrNull;
}

/// Request body for `/checkout/quote` and `/checkout/orders`.
@immutable
class CheckoutOptions {
  const CheckoutOptions({
    this.fulfillmentType = FulfillmentType.delivery,
    this.addressId,
    this.branchId,
    this.pickupSlotId,
    this.pickupDate,
    this.deliverySlotId,
    this.deliveryDate,
    this.paymentMethod,
    this.couponCode,
    this.loyaltyPoints = 0,
    this.allowSplit = true,
    this.express = false,
  });

  final FulfillmentType fulfillmentType;
  final String? addressId;
  final String? branchId;
  final String? pickupSlotId;
  final String? pickupDate;
  final String? deliverySlotId;
  final String? deliveryDate;
  final PaymentMethod? paymentMethod;
  final String? couponCode;
  final int loyaltyPoints;
  final bool allowSplit;
  final bool express;

  CheckoutOptions copyWith({
    FulfillmentType? fulfillmentType,
    String? Function()? addressId,
    String? Function()? branchId,
    String? Function()? pickupSlotId,
    String? Function()? pickupDate,
    String? Function()? deliverySlotId,
    String? Function()? deliveryDate,
    PaymentMethod? Function()? paymentMethod,
    String? Function()? couponCode,
    int? loyaltyPoints,
    bool? allowSplit,
  }) => CheckoutOptions(
    fulfillmentType: fulfillmentType ?? this.fulfillmentType,
    addressId: addressId != null ? addressId() : this.addressId,
    branchId: branchId != null ? branchId() : this.branchId,
    pickupSlotId: pickupSlotId != null ? pickupSlotId() : this.pickupSlotId,
    pickupDate: pickupDate != null ? pickupDate() : this.pickupDate,
    deliverySlotId: deliverySlotId != null ? deliverySlotId() : this.deliverySlotId,
    deliveryDate: deliveryDate != null ? deliveryDate() : this.deliveryDate,
    paymentMethod: paymentMethod != null ? paymentMethod() : this.paymentMethod,
    couponCode: couponCode != null ? couponCode() : this.couponCode,
    loyaltyPoints: loyaltyPoints ?? this.loyaltyPoints,
    allowSplit: allowSplit ?? this.allowSplit,
    express: express,
  );

  JsonMap toJson() => {
    'fulfillment_type': fulfillmentType.api,
    if (fulfillmentType == FulfillmentType.delivery) 'address_id': ?addressId,
    'branch_id': ?branchId,
    // The backend wants a slot id and its date together, or neither.
    if (fulfillmentType == FulfillmentType.pickup && pickupSlotId != null && pickupDate != null) ...{
      'pickup_slot_id': pickupSlotId,
      'pickup_date': pickupDate,
    },
    // The backend wants the slot id and its date together, or neither.
    if (fulfillmentType == FulfillmentType.delivery && deliverySlotId != null && deliveryDate != null) ...{
      'delivery_slot_id': deliverySlotId,
      'delivery_date': deliveryDate,
    },
    'payment_method': ?paymentMethod?.api,
    if (couponCode != null && couponCode!.isNotEmpty) 'coupon_code': couponCode,
    'loyalty_points': loyaltyPoints,
    'allow_split': allowSplit,
    'express': express,
  };

  @override
  bool operator ==(Object other) => other is CheckoutOptions && mapEquals(other.toJson(), toJson());

  @override
  int get hashCode => Object.hashAll(toJson().entries.map((e) => Object.hash(e.key, e.value)));
}

@immutable
class QuoteLine {
  const QuoteLine({
    required this.variantId,
    required this.name,
    required this.quantity,
    required this.lineTotal,
    this.variantName,
    this.imageUrl,
    this.unitPrice,
    this.lineSubtotal,
    this.discount,
    this.branchId,
  });
  factory QuoteLine.fromJson(JsonMap json) => QuoteLine(
    variantId: asString(json['variant_id']),
    name: asString(json['name']),
    variantName: asStringOrNull(json['variant_name']),
    imageUrl: asStringOrNull(json['image_url']),
    quantity: asInt(json['quantity'], 1),
    unitPrice: EffectivePrice.maybe(json['unit_price']),
    lineSubtotal: Money.maybe(json['line_subtotal']),
    discount: Money.maybe(json['discount']),
    lineTotal: Money.maybe(json['line_total']) ?? Money.zero,
    branchId: asStringOrNull(json['branch_id']),
  );
  final String variantId;
  final String name;
  final String? variantName;
  final String? imageUrl;
  final int quantity;
  final EffectivePrice? unitPrice;
  final Money? lineSubtotal;
  final Money? discount;
  final Money lineTotal;
  final String? branchId;
}

/// `fulfillments[i].slot` of a quote: `{id, date, starts_at, ends_at}`.
@immutable
class QuoteSlot {
  const QuoteSlot({required this.id, required this.date, required this.startsAt, required this.endsAt});
  static QuoteSlot? maybe(Object? json) {
    if (json is! Map) return null;
    final m = asMap(json);
    return QuoteSlot(
      id: asString(m['id']),
      date: asString(m['date']),
      startsAt: asString(m['starts_at']),
      endsAt: asString(m['ends_at']),
    );
  }

  final String id;
  final String date;
  final String startsAt;
  final String endsAt;
}

@immutable
class QuoteFulfillment {
  const QuoteFulfillment({
    required this.branch,
    required this.type,
    required this.items,
    this.subtotal,
    this.deliveryFee,
    this.etaMinutes,
    this.slot,
    this.slotRequired = false,
  });
  factory QuoteFulfillment.fromJson(JsonMap json) => QuoteFulfillment(
    branch: NamedRef.fromJson(asMap(json['branch'])),
    type: asString(json['type'], 'delivery'),
    items: mapList(json['items'], QuoteLine.fromJson),
    subtotal: Money.maybe(json['subtotal']),
    deliveryFee: Money.maybe(json['delivery_fee']),
    etaMinutes: asIntOrNull(json['eta_minutes']),
    slot: QuoteSlot.maybe(json['slot']),
    slotRequired: asBool(json['slot_required']),
  );
  final NamedRef branch;
  final String type;
  final List<QuoteLine> items;
  final Money? subtotal;
  final Money? deliveryFee;
  final int? etaMinutes;
  final QuoteSlot? slot;

  /// True for a single-branch delivery whose branch publishes delivery slots.
  final bool slotRequired;
}

/// Totals object shared by Quote and Order.
@immutable
class OrderTotals {
  const OrderTotals({
    required this.subtotal,
    required this.grandTotal,
    this.discount,
    this.deliveryFee,
    this.serviceFee,
    this.codFee,
    this.tax,
    this.loyaltyDiscount,
  });
  factory OrderTotals.fromJson(JsonMap json) => OrderTotals(
    subtotal: Money.maybe(json['subtotal']) ?? Money.zero,
    discount: Money.maybe(json['discount']),
    deliveryFee: Money.maybe(json['delivery_fee']),
    serviceFee: Money.maybe(json['service_fee']),
    codFee: Money.maybe(json['cod_fee']),
    tax: Money.maybe(json['tax']),
    loyaltyDiscount: Money.maybe(json['loyalty_discount']),
    grandTotal: Money.maybe(json['grand_total']) ?? Money.zero,
  );
  final Money subtotal;
  final Money? discount;
  final Money? deliveryFee;
  final Money? serviceFee;
  final Money? codFee;
  final Money? tax;
  final Money? loyaltyDiscount;
  final Money grandTotal;
}

@immutable
class QuoteDiscount {
  const QuoteDiscount({required this.label, this.code, required this.amount});
  factory QuoteDiscount.fromJson(JsonMap json) => QuoteDiscount(
    label: asString(json['label']),
    code: asStringOrNull(json['code']),
    amount: Money.maybe(json['amount']) ?? Money.zero,
  );
  final String label;
  final String? code;
  final Money amount;
}

@immutable
class PaymentOption {
  const PaymentOption({required this.code, required this.available, this.reason});
  factory PaymentOption.fromJson(JsonMap json) => PaymentOption(
    code: asString(json['code']),
    available: asBool(json['available']),
    reason: asStringOrNull(json['reason']),
  );
  final String code;
  final bool available;
  final String? reason;

  PaymentMethod? get method => PaymentMethod.parse(code);
}

@immutable
class QuoteLoyalty {
  const QuoteLoyalty({required this.balance, required this.maxRedeemablePoints, required this.pointsToEarn});
  factory QuoteLoyalty.fromJson(JsonMap json) => QuoteLoyalty(
    balance: asInt(json['balance']),
    maxRedeemablePoints: asInt(json['max_redeemable_points']),
    pointsToEarn: asInt(json['points_to_earn']),
  );
  final int balance;
  final int maxRedeemablePoints;
  final int pointsToEarn;
}

@immutable
class QuoteIssue {
  const QuoteIssue({required this.code, required this.message, this.variantId});
  factory QuoteIssue.fromJson(JsonMap json) => QuoteIssue(
    code: asString(json['code']),
    message: asString(json['message']),
    variantId: asStringOrNull(json['variant_id']),
  );
  final String code;
  final String message;
  final String? variantId;
}

@immutable
class Quote {
  const Quote({
    required this.quoteHash,
    required this.fulfillments,
    required this.isSplit,
    required this.lines,
    required this.totals,
    required this.discounts,
    required this.paymentMethods,
    required this.warnings,
    required this.errors,
    required this.canPlaceOrder,
    this.loyalty,
    this.expiresAt,
    this.currency = 'QAR',
    this.splitChoice = false,
  });

  factory Quote.fromJson(JsonMap json) => Quote(
    quoteHash: asString(json['quote_hash']),
    currency: asString(json['currency'], 'QAR'),
    fulfillments: mapList(json['fulfillments'], QuoteFulfillment.fromJson),
    isSplit: asBool(json['is_split']),
    splitChoice: asBool(json['split_choice']),
    lines: mapList(json['lines'], QuoteLine.fromJson),
    totals: OrderTotals.fromJson(asMap(json['totals'])),
    discounts: mapList(json['discounts'], QuoteDiscount.fromJson),
    paymentMethods: mapList(json['payment_methods'], PaymentOption.fromJson),
    loyalty: json['loyalty'] is Map ? QuoteLoyalty.fromJson(asMap(json['loyalty'])) : null,
    warnings: mapList(json['warnings'], QuoteIssue.fromJson),
    errors: mapList(json['errors'], QuoteIssue.fromJson),
    canPlaceOrder: asBool(json['can_place_order']),
    expiresAt: asDate(json['expires_at']),
  );

  final String quoteHash;
  final String currency;
  final List<QuoteFulfillment> fulfillments;
  final bool isSplit;

  /// The server says the "allow split shipments" switch is worth showing: the admin setting is on AND more than one active
  /// branch holds part of the cart. False (or absent, from an older server) hides the switch.
  final bool splitChoice;
  final List<QuoteLine> lines;
  final OrderTotals totals;
  final List<QuoteDiscount> discounts;
  final List<PaymentOption> paymentMethods;
  final QuoteLoyalty? loyalty;
  final List<QuoteIssue> warnings;
  final List<QuoteIssue> errors;
  final bool canPlaceOrder;
  final DateTime? expiresAt;

  PaymentOption? option(PaymentMethod method) => paymentMethods.where((p) => p.code == method.api).firstOrNull;

  /// The only fulfilment of a non-split quote (slots only apply there).
  QuoteFulfillment? get singleFulfillment => !isSplit && fulfillments.length == 1 ? fulfillments.first : null;

  /// A delivery slot must be chosen before this quote can be placed.
  bool get slotRequired {
    final f = singleFulfillment;
    return f != null && f.type == 'delivery' && f.slotRequired;
  }

  /// Delivery window the server holds for this quote (non-split only).
  QuoteSlot? get deliverySlot {
    final f = singleFulfillment;
    return f != null && f.type == 'delivery' ? f.slot : null;
  }

  /// Pickup time the server holds for this quote (non-split only).
  QuoteSlot? get pickupSlot {
    final f = singleFulfillment;
    return f != null && f.type == 'pickup' ? f.slot : null;
  }

  QuoteIssue? issue(String code) => [...errors, ...warnings].where((w) => w.code == code).firstOrNull;

  /// Coupon problem reported by the quote (`coupon_invalid`, `coupon_expired`, …).
  QuoteIssue? get couponIssue => [...errors, ...warnings].where((w) => w.code.startsWith('coupon_')).firstOrNull;
}

/// Returned with new orders and by `POST /orders/{id}/payments`.
@immutable
class PaymentAction {
  const PaymentAction({
    required this.paymentId,
    required this.method,
    required this.status,
    required this.action,
    this.redirectUrl,
    this.returnUrlScheme,
    this.expiresAt,
  });

  factory PaymentAction.fromJson(JsonMap json) => PaymentAction(
    paymentId: asString(json['payment_id'] ?? json['id']),
    method: asString(json['method']),
    status: asString(json['status'], 'pending'),
    action: asString(json['action'], 'none'),
    redirectUrl: asStringOrNull(json['redirect_url']),
    returnUrlScheme: asStringOrNull(json['return_url_scheme']),
    expiresAt: asDate(json['expires_at']),
  );

  final String paymentId;
  final String method;
  final String status;

  /// `redirect` | `none`.
  final String action;
  final String? redirectUrl;
  final String? returnUrlScheme;
  final DateTime? expiresAt;

  bool get requiresRedirect => action == 'redirect' && redirectUrl != null;
}

/// `GET /payments/{id}`.
@immutable
class PaymentStatus {
  const PaymentStatus({
    required this.id,
    required this.status,
    this.method,
    this.amount,
    this.paidAt,
    this.failureReason,
    this.orderId,
  });

  factory PaymentStatus.fromJson(JsonMap json) => PaymentStatus(
    id: asString(json['id']),
    status: asString(json['status'], 'pending'),
    method: asStringOrNull(json['method']),
    amount: Money.maybe(json['amount']),
    paidAt: asDate(json['paid_at']),
    failureReason: asStringOrNull(json['failure_reason']),
    orderId: asStringOrNull(json['order_id'] ?? asMapOrNull(json['order'])?['id']),
  );

  static const terminal = {'paid', 'failed', 'expired', 'cancelled', 'refunded', 'partially_refunded'};

  final String id;
  final String status;
  final String? method;
  final Money? amount;
  final DateTime? paidAt;
  final String? failureReason;
  final String? orderId;

  bool get isTerminal => terminal.contains(status);
  bool get isPaid => status == 'paid' || status == 'authorized';
}

/// Outcome of placing an order.
@immutable
class PlacedOrder {
  const PlacedOrder({required this.orderId, required this.orderNumber, this.payment, this.raw = const {}});
  factory PlacedOrder.fromJson(JsonMap json) {
    final order = asMap(json['order']);
    return PlacedOrder(
      orderId: asString(order['id']),
      orderNumber: asString(order['number']),
      payment: json['payment'] is Map ? PaymentAction.fromJson(asMap(json['payment'])) : null,
      raw: order,
    );
  }
  final String orderId;
  final String orderNumber;
  final PaymentAction? payment;
  final JsonMap raw;
}
