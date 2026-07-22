import '../entities/checkout_entities.dart';

/// Abstract Checkout repository (docs/09_Flutter_Architecture.md §2.1 —
/// `domain/repositories` contract; same real-repository-seam approach the
/// Orders Module uses, for the same reason: a backend swap
/// (`POST /api/v1/checkout`, `POST /api/v1/store-orders`) is a near-term,
/// same-shape follow-up).
abstract interface class CheckoutRepository {
  /// Checkout preview: re-priced cart contents (docs/06_API_Design.md
  /// §7.6 — "Validates stock/Selling Prices... returns a checkout preview
  /// summary only"). This module has no live Cart to read (Store's Cart is
  /// frozen and features must not import another feature's presentation,
  /// docs/09_Flutter_Architecture.md §3.1), so the preview is a
  /// self-contained mock snapshot — the same "own copy, not cross-feature
  /// state" approach the Orders Module used for its order history.
  Future<CheckoutSummary> fetchCheckoutSummary();

  Future<List<DeliveryAddressOption>> fetchSavedAddresses();

  /// Creates a Store Order (docs/06_API_Design.md §7.6A). Never decreases
  /// stock here — that remains a backend-only concern this mock never
  /// models.
  Future<PlacedOrderResult> placeOrder({
    required String addressId,
    required String contactPhone,
    required CheckoutPaymentMethod paymentMethod,
    String? notes,
  });
}
