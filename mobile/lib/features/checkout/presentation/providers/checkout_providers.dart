import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/mock_checkout_repository.dart';
import '../../domain/entities/checkout_entities.dart';
import '../../domain/repositories/checkout_repository.dart';

final checkoutRepositoryProvider = Provider<CheckoutRepository>((ref) => MockCheckoutRepository());

final checkoutSummaryProvider = FutureProvider<CheckoutSummary>((ref) {
  return ref.watch(checkoutRepositoryProvider).fetchCheckoutSummary();
});

final savedAddressesProvider = FutureProvider<List<DeliveryAddressOption>>((ref) {
  return ref.watch(checkoutRepositoryProvider).fetchSavedAddresses();
});

class SelectedAddressNotifier extends Notifier<String?> {
  @override
  String? build() => null;

  void select(String id) => state = id;
}

final selectedAddressProvider = NotifierProvider<SelectedAddressNotifier, String?>(SelectedAddressNotifier.new);

class SelectedPaymentMethodNotifier extends Notifier<CheckoutPaymentMethod> {
  @override
  CheckoutPaymentMethod build() => CheckoutPaymentMethod.evcPlus;

  void select(CheckoutPaymentMethod method) => state = method;
}

final selectedPaymentMethodProvider =
    NotifierProvider<SelectedPaymentMethodNotifier, CheckoutPaymentMethod>(SelectedPaymentMethodNotifier.new);

class ContactPhoneNotifier extends Notifier<String> {
  @override
  String build() => '';

  void update(String value) => state = value;
}

final contactPhoneProvider = NotifierProvider<ContactPhoneNotifier, String>(ContactPhoneNotifier.new);

class CheckoutNotesNotifier extends Notifier<String> {
  @override
  String build() => '';

  void update(String value) => state = value;
}

final checkoutNotesProvider = NotifierProvider<CheckoutNotesNotifier, String>(CheckoutNotesNotifier.new);

/// Place Order action + its own Loading/Data/Error surface, separate from
/// the read-only summary/address providers above — `null` data means "not
/// submitted yet", not "failed".
class PlaceOrderNotifier extends AsyncNotifier<PlacedOrderResult?> {
  @override
  PlacedOrderResult? build() => null;

  Future<void> submit() async {
    final String? addressId = ref.read(selectedAddressProvider);
    if (addressId == null) {
      return;
    }
    state = const AsyncLoading<PlacedOrderResult?>();
    state = await AsyncValue.guard(() {
      final String notes = ref.read(checkoutNotesProvider);
      return ref.read(checkoutRepositoryProvider).placeOrder(
            addressId: addressId,
            contactPhone: ref.read(contactPhoneProvider),
            paymentMethod: ref.read(selectedPaymentMethodProvider),
            notes: notes.isEmpty ? null : notes,
          );
    });
  }
}

final placeOrderProvider = AsyncNotifierProvider<PlaceOrderNotifier, PlacedOrderResult?>(PlaceOrderNotifier.new);
