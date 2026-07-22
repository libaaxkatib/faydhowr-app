import 'package:flutter/foundation.dart';

/// Plain entities for the Orders Module (V1) — mock data only, no backend
/// (docs/09_Flutter_Architecture.md §1.3A — no live payment/order gateway
/// in V1 anyway). Field names mirror the approved schema
/// (docs/03_Database_Design.md §3.7.1 `orders`; docs/06_API_Design.md
/// §7.6A `store_orders`) so a future repository swap is a drop-in.
///
/// Store Order lifecycle (docs/06_API_Design.md §7.6A):
/// `pending_payment` → `confirmed` → `preparing` → `out_for_delivery` →
/// `delivered` → (`payment_pending`, Cash on Delivery only) → `completed`,
/// or → `cancelled`. This module surfaces exactly the 6 customer-facing
/// states the approved UI spec calls for; the COD-only `payment_pending`
/// admin-verification step and the terminal `completed` flag both collapse
/// into [OrderStatus.delivered] for display — the customer sees "Delivered"
/// either way, matching docs/05_UI_UX_Design.md §13.2's Active/Completed
/// grouping rather than exposing an internal admin-only distinction.
enum OrderStatus {
  pending('Pending'),
  confirmed('Confirmed'),
  preparing('Preparing'),
  outForDelivery('Out for Delivery'),
  delivered('Delivered'),
  cancelled('Cancelled');

  const OrderStatus(this.label);

  final String label;

  /// Position in the happy-path timeline (docs/05_UI_UX_Design.md §13.2's
  /// "Order Placed → Confirmed → Preparing → Out for Delivery →
  /// Delivered"). [cancelled] has no timeline position — callers must
  /// branch on it separately rather than comparing this index.
  int get timelineIndex => switch (this) {
    OrderStatus.pending => 0,
    OrderStatus.confirmed => 1,
    OrderStatus.preparing => 2,
    OrderStatus.outForDelivery => 3,
    OrderStatus.delivered => 4,
    OrderStatus.cancelled => -1,
  };
}

/// V1 payment methods (docs/06_API_Design.md line ~1056, §7.6 checkout).
enum OrderPaymentMethod {
  evcPlus('EVC Plus'),
  eDahab('eDahab'),
  bankTransfer('Bank Transfer'),
  cashOnDelivery('Cash on Delivery');

  const OrderPaymentMethod(this.label);

  final String label;
}

@immutable
class OrderLineItem {
  const OrderLineItem({required this.productName, required this.quantity, required this.unitPrice, required this.unit});

  final String productName;
  final int quantity;
  final double unitPrice;
  final String unit;

  double get lineTotal => unitPrice * quantity;
}

/// Orders List card fields (docs/05_UI_UX_Design.md §4.13, §13.2).
@immutable
class OrderPreview {
  const OrderPreview({
    required this.id,
    required this.orderNumber,
    required this.placedAt,
    required this.status,
    required this.totalAmount,
    required this.itemCount,
    required this.firstItemName,
  });

  final String id;

  /// `STO-YYYY-######` (docs/06_API_Design.md §7.6A).
  final String orderNumber;
  final DateTime placedAt;
  final OrderStatus status;
  final double totalAmount;
  final int itemCount;

  /// Backs the card thumbnail's accessible label — no approved product
  /// photography exists yet (same reasoning as the Store Module), so the
  /// thumbnail itself stays a placeholder icon.
  final String firstItemName;

  OrderPreview copyWith({OrderStatus? status}) => OrderPreview(
    id: id,
    orderNumber: orderNumber,
    placedAt: placedAt,
    status: status ?? this.status,
    totalAmount: totalAmount,
    itemCount: itemCount,
    firstItemName: firstItemName,
  );
}

/// Order Details fields (docs/05_UI_UX_Design.md §4.13; docs/03_Database_Design.md
/// §3.7.1 `orders` columns: subtotal_amount, discount_amount, delivery_fee,
/// total_amount, shipping_address_snapshot, customer_notes).
@immutable
class OrderDetail {
  const OrderDetail({
    required this.preview,
    required this.items,
    required this.deliveryAddress,
    required this.subtotal,
    required this.deliveryFee,
    required this.total,
    required this.paymentMethod,
    this.discount = 0,
    this.notes,
  });

  final OrderPreview preview;
  final List<OrderLineItem> items;
  final String deliveryAddress;
  final double subtotal;
  final double deliveryFee;

  /// `0` means "no discount applied" — docs call for a placeholder row
  /// when none exists, not for hiding the concept entirely.
  final double discount;
  final double total;
  final OrderPaymentMethod paymentMethod;
  final String? notes;

  OrderDetail copyWith({OrderPreview? preview}) => OrderDetail(
    preview: preview ?? this.preview,
    items: items,
    deliveryAddress: deliveryAddress,
    subtotal: subtotal,
    deliveryFee: deliveryFee,
    total: total,
    paymentMethod: paymentMethod,
    discount: discount,
    notes: notes,
  );
}
