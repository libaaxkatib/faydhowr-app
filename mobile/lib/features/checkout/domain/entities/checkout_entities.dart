import 'package:flutter/foundation.dart';

/// Plain entities for the Checkout Module (V1) — mock data only, no
/// backend (docs/09_Flutter_Architecture.md §1.3A: no live payment gateway
/// in V1 regardless). Field names mirror the approved Checkout/Store Order
/// contract (docs/06_API_Design.md §7.6, §7.6A) so a future repository
/// swap is a drop-in.
///
/// V1 payment methods (docs/06_API_Design.md §7.6 — store checkout):
/// EVC Plus (default, prepaid), eDahab (prepaid), Bank Transfer (prepaid),
/// Cash on Delivery.
enum CheckoutPaymentMethod {
  evcPlus('EVC Plus', isPrepaid: true),
  eDahab('eDahab', isPrepaid: true),
  bankTransfer('Bank Transfer', isPrepaid: true),
  cashOnDelivery('Cash on Delivery', isPrepaid: false);

  const CheckoutPaymentMethod(this.label, {required this.isPrepaid});

  final String label;

  /// Prepaid methods create the Store Order as `pending_payment` (awaiting
  /// admin-verified payment); Cash on Delivery confirms immediately
  /// (docs/06_API_Design.md §7.6A).
  final bool isPrepaid;
}

@immutable
class CheckoutLineItem {
  const CheckoutLineItem({required this.productName, required this.quantity, required this.unitPrice, required this.unit});

  final String productName;
  final int quantity;
  final double unitPrice;
  final String unit;

  double get lineTotal => unitPrice * quantity;
}

@immutable
class DeliveryAddressOption {
  const DeliveryAddressOption({required this.id, required this.title, required this.subtitle});

  final String id;
  final String title;
  final String subtitle;
}

/// The current cart, re-priced for checkout preview
/// (docs/06_API_Design.md §7.6 — "re-validates Selling Price and stock").
@immutable
class CheckoutSummary {
  const CheckoutSummary({
    required this.items,
    required this.subtotal,
    required this.deliveryFee,
    required this.total,
    this.discount = 0,
  });

  final List<CheckoutLineItem> items;
  final double subtotal;
  final double deliveryFee;
  final double discount;
  final double total;

  int get itemCount => items.fold(0, (int sum, CheckoutLineItem i) => sum + i.quantity);
}

/// Store Order lifecycle immediately after creation (docs/06_API_Design.md
/// §7.6A): prepaid methods start `pending_payment`; Cash on Delivery
/// starts `confirmed` immediately.
enum PlacedOrderStatus {
  pendingPayment('Pending Payment'),
  confirmed('Confirmed');

  const PlacedOrderStatus(this.label);

  final String label;
}

@immutable
class PlacedOrderResult {
  const PlacedOrderResult({
    required this.orderNumber,
    required this.status,
    required this.paymentMethod,
    required this.total,
    required this.placedAt,
  });

  /// `STO-YYYY-######` (docs/06_API_Design.md §7.6A).
  final String orderNumber;
  final PlacedOrderStatus status;
  final CheckoutPaymentMethod paymentMethod;
  final double total;
  final DateTime placedAt;
}
