import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/booking_mock_data.dart';
import '../../domain/entities/booking_entities.dart';

/// Mock-backed catalog provider for the Booking Module (Phase 1) — mirrors
/// the Home/Services Modules' pattern of a trivial `Provider` wrapping
/// constant mock data.
final bookingServiceCatalogProvider = Provider<List<BookableService>>((ref) => bookingServiceCatalog);

final bookingServicesByIdProvider = Provider<Map<String, BookableService>>(
  (ref) => <String, BookableService>{
    for (final BookableService service in ref.watch(bookingServiceCatalogProvider)) service.id: service,
  },
);

/// The in-progress Booking form for the service currently being booked.
/// Hand-written `Notifier` — this Riverpod version has no `StateProvider`,
/// same pattern as `ServicesSearchQueryNotifier`.
///
/// Not a `family` provider: the app only ever has one Booking Screen open
/// at a time, so a single draft plus an explicit [resetForService] (called
/// once from the screen's `initState`) is simpler than a family/autoDispose
/// setup, and avoids re-deriving Riverpod 3's family API for a case this
/// small.
class BookingDraftNotifier extends Notifier<BookingDraft> {
  @override
  BookingDraft build() => const BookingDraft();

  /// Starts a fresh draft when a new service is being booked (a stale
  /// draft from a previously-booked service must never leak into a new
  /// one); a no-op if the screen rebuilds for the same service.
  void resetForService(String serviceId) {
    if (state.serviceId != serviceId) {
      state = BookingDraft(serviceId: serviceId);
    }
  }

  void selectBookingType(BookingType type) => state = state.copyWith(bookingType: type);

  void selectPropertyType(PropertyType type) => state = state.copyWith(propertyType: type);

  void updateCustomPropertyType(String text) => state = state.copyWith(customPropertyType: text);

  void selectPropertySize(String sizeId) => state = state.copyWith(propertySizeId: sizeId);

  void selectDate(String dateLabel) => state = state.copyWith(preferredDateLabel: dateLabel);

  void selectTimeSlot(PreferredTimeSlot slot) => state = state.copyWith(preferredTime: slot);

  void selectAddress(String addressLabel) => state = state.copyWith(addressLabel: addressLabel);

  void updateNotes(String notes) => state = state.copyWith(notes: notes);

  void togglePhotos() => state = state.copyWith(photosAdded: !state.photosAdded);

  void toggleVideo() => state = state.copyWith(videoAdded: !state.videoAdded);
}

final bookingDraftProvider = NotifierProvider<BookingDraftNotifier, BookingDraft>(BookingDraftNotifier.new);
