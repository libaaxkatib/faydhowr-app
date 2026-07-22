import '../domain/entities/order_entities.dart';
import '../domain/repositories/orders_repository.dart';
import 'orders_mock_data.dart';

/// Mock [OrdersRepository] — simulates a network round-trip with a fixed
/// delay so the presentation layer's Loading state is real, not skipped.
/// [cancelOrder] mutates its own in-memory copy of the mock catalog (never
/// the shared `mockOrderDetails` constant list) so repeated app runs start
/// fresh, the same convention the Store Module's `CartNotifier` uses for
/// its local-only mutations.
class MockOrdersRepository implements OrdersRepository {
  final List<OrderDetail> _orders = List<OrderDetail>.of(mockOrderDetails);

  @override
  Future<List<OrderPreview>> fetchOrders() async {
    await Future<void>.delayed(const Duration(milliseconds: 600));
    final List<OrderPreview> previews = _orders.map((OrderDetail o) => o.preview).toList();
    // Newest first — guaranteed by sort here rather than relying on mock
    // data happening to be authored in that order.
    previews.sort((OrderPreview a, OrderPreview b) => b.placedAt.compareTo(a.placedAt));
    return previews;
  }

  @override
  Future<OrderDetail?> fetchOrderDetail(String orderId) async {
    await Future<void>.delayed(const Duration(milliseconds: 400));
    for (final OrderDetail order in _orders) {
      if (order.preview.id == orderId) {
        return order;
      }
    }
    return null;
  }

  @override
  Future<void> cancelOrder(String orderId) async {
    final int index = _orders.indexWhere((OrderDetail o) => o.preview.id == orderId);
    if (index == -1) {
      return;
    }
    final OrderDetail current = _orders[index];
    if (current.preview.status != OrderStatus.pending) {
      throw StateError('Only a Pending order can be cancelled.');
    }
    await Future<void>.delayed(const Duration(milliseconds: 400));
    _orders[index] = current.copyWith(preview: current.preview.copyWith(status: OrderStatus.cancelled));
  }
}
