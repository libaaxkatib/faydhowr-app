import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/mock_orders_repository.dart';
import '../../domain/entities/order_entities.dart';
import '../../domain/repositories/orders_repository.dart';

/// Single [OrdersRepository] instance for the app's lifetime — mirrors how
/// `core/di/root_providers.dart` binds interface → impl providers, kept
/// local to this feature since nothing outside Orders needs it.
final ordersRepositoryProvider = Provider<OrdersRepository>((ref) => MockOrdersRepository());

/// Orders List — real Loading/Data/Error via `AsyncValue`
/// (docs/09_Flutter_Architecture.md §4.1), same shape as the Store Module's
/// `StoreCatalogNotifier`.
class OrdersListNotifier extends AsyncNotifier<List<OrderPreview>> {
  @override
  Future<List<OrderPreview>> build() {
    return ref.watch(ordersRepositoryProvider).fetchOrders();
  }

  Future<void> refresh() async {
    state = await AsyncValue.guard(() => ref.read(ordersRepositoryProvider).fetchOrders());
  }
}

final ordersListProvider = AsyncNotifierProvider<OrdersListNotifier, List<OrderPreview>>(OrdersListNotifier.new);

/// Order Details, keyed by order id.
final orderDetailProvider = FutureProvider.family<OrderDetail?, String>((ref, String orderId) {
  return ref.watch(ordersRepositoryProvider).fetchOrderDetail(orderId);
});

/// Cancels [orderId] (only valid while [OrderStatus.pending] — enforced by
/// the repository) and invalidates both the detail and list providers so
/// the UI reflects the new status without a manual refresh.
Future<void> cancelOrder(WidgetRef ref, String orderId) async {
  await ref.read(ordersRepositoryProvider).cancelOrder(orderId);
  ref.invalidate(orderDetailProvider(orderId));
  ref.invalidate(ordersListProvider);
}
