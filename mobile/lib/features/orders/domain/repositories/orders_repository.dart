import '../entities/order_entities.dart';

/// Abstract Orders repository (docs/09_Flutter_Architecture.md §2.1 —
/// `domain/repositories` contract, implementation lives in `data/`). Unlike
/// Home/Services/Booking/Store's simpler "trivial `Provider` over a mock
/// list" pattern, Orders uses a real repository seam here because a future
/// backend swap (`GET /api/v1/store-orders`, `PATCH .../cancel`) is a
/// same-shape, near-term follow-up for this specific module — the
/// interface is written so [MockOrdersRepository] can be replaced by a
/// Dio-backed implementation without touching any provider or widget.
abstract interface class OrdersRepository {
  Future<List<OrderPreview>> fetchOrders();

  Future<OrderDetail?> fetchOrderDetail(String orderId);

  /// Cancels the order if [OrderStatus.pending] (docs/06_API_Design.md
  /// §7.6A — "Cancel: Allowed only while `pending_payment`"). Throws a
  /// [StateError] if called on any other status; callers must check first.
  Future<void> cancelOrder(String orderId);
}
