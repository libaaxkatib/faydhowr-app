import '../domain/entities/checkout_entities.dart';
import '../domain/repositories/checkout_repository.dart';
import 'checkout_mock_data.dart';

/// Mock [CheckoutRepository] — simulates a network round-trip with a fixed
/// delay so Loading is real, not skipped (same convention as
/// `MockOrdersRepository`).
class MockCheckoutRepository implements CheckoutRepository {
  int _sequence = 108;

  @override
  Future<CheckoutSummary> fetchCheckoutSummary() async {
    await Future<void>.delayed(const Duration(milliseconds: 500));
    const double subtotal = 8.50 * 2 + 7.00 + 5.50;
    const double deliveryFee = 3.00;
    const double discount = 0;
    return const CheckoutSummary(
      items: mockCheckoutItems,
      subtotal: subtotal,
      deliveryFee: deliveryFee,
      discount: discount,
      total: subtotal + deliveryFee - discount,
    );
  }

  @override
  Future<List<DeliveryAddressOption>> fetchSavedAddresses() async {
    await Future<void>.delayed(const Duration(milliseconds: 400));
    return mockSavedAddresses;
  }

  @override
  Future<PlacedOrderResult> placeOrder({
    required String addressId,
    required String contactPhone,
    required CheckoutPaymentMethod paymentMethod,
    String? notes,
  }) async {
    await Future<void>.delayed(const Duration(milliseconds: 900));
    final CheckoutSummary summary = await fetchCheckoutSummary();
    _sequence += 1;
    final DateTime now = DateTime.now();
    return PlacedOrderResult(
      orderNumber: 'STO-${now.year}-${_sequence.toString().padLeft(6, '0')}',
      status: paymentMethod.isPrepaid ? PlacedOrderStatus.pendingPayment : PlacedOrderStatus.confirmed,
      paymentMethod: paymentMethod,
      total: summary.total,
      placedAt: now,
    );
  }
}
