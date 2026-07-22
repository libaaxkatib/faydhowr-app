import 'package:flutter/material.dart';

/// Plain entities for the Booking Module (Phase 1) — mock data only, no
/// repository/backend yet, matching the Home and Services Modules'
/// established pattern.
///
/// [BookableService] is a deliberately separate, Booking-scoped copy of the
/// service identity fields also modeled by `features/services/domain`'s
/// `ServicePreview` — features must not import another feature's `data` or
/// `presentation` (docs/09_Flutter_Architecture.md §3.1), so Booking keeps
/// its own minimal copy rather than reaching into Services, the same way
/// the Home Module keeps its own separate service preview entities.
@immutable
class BookableService {
  const BookableService({
    required this.id,
    required this.name,
    required this.icon,
    required this.shortDescription,
  });

  final String id;
  final String name;
  final IconData icon;
  final String shortDescription;
}

enum BookingType {
  oneTime('One-Time'),
  monthlyContract('Monthly Contract');

  const BookingType(this.label);

  final String label;
}

enum PropertyType {
  apartment('Apartment', Icons.apartment_outlined),
  villa('Villa', Icons.villa_outlined),
  office('Office', Icons.business_center_outlined),
  shop('Shop', Icons.storefront_outlined),
  restaurant('Restaurant', Icons.restaurant_outlined),
  warehouse('Warehouse', Icons.warehouse_outlined),
  school('School', Icons.school_outlined),
  hall('Hall', Icons.meeting_room_outlined),
  hotel('Hotel', Icons.hotel_outlined),
  hospitalClinic('Hospital / Clinic', Icons.local_hospital_outlined),
  // Reveals a free-text field on the Booking Screen so the customer can
  // name a property type outside this mock list — UI/mock-data only, no
  // backend validation of the entered text.
  other('Other', Icons.more_horiz_outlined);

  const PropertyType(this.label, this.icon);

  final String label;
  final IconData icon;
}

@immutable
class PropertySizeOption {
  const PropertySizeOption({required this.id, required this.label});

  final String id;
  final String label;
}

enum PreferredTimeSlot {
  morning('Morning', Icons.wb_sunny_outlined),
  afternoon('Afternoon', Icons.wb_twilight_outlined),
  evening('Evening', Icons.nights_stay_outlined);

  const PreferredTimeSlot(this.label, this.icon);

  final String label;
  final IconData icon;
}

@immutable
class BookingAddressOption {
  const BookingAddressOption({required this.id, required this.title, required this.subtitle});

  final String id;
  final String title;
  final String subtitle;
}

/// The in-progress Booking form. Every field starts unset; [isComplete]
/// drives the sticky Continue button's disabled → enabled transition —
/// Phase 1 has no submission logic, this only reflects whether the visible
/// form is filled in.
@immutable
class BookingDraft {
  const BookingDraft({
    this.serviceId,
    this.bookingType,
    this.propertyType,
    this.propertySizeId,
    this.preferredDateLabel,
    this.preferredTime,
    this.addressLabel,
    this.notes = '',
    this.photosAdded = false,
    this.videoAdded = false,
    this.customPropertyType = '',
  });

  final String? serviceId;
  final BookingType? bookingType;
  final PropertyType? propertyType;
  final String? propertySizeId;
  final String? preferredDateLabel;
  final PreferredTimeSlot? preferredTime;
  final String? addressLabel;
  final String notes;
  final bool photosAdded;
  final bool videoAdded;

  /// Free-text entered when [propertyType] is [PropertyType.other]. Not
  /// required for [isComplete] — Phase 1 adds no validation on it.
  final String customPropertyType;

  bool get isComplete =>
      bookingType != null &&
      propertyType != null &&
      propertySizeId != null &&
      preferredDateLabel != null &&
      preferredTime != null &&
      addressLabel != null;

  BookingDraft copyWith({
    String? serviceId,
    BookingType? bookingType,
    PropertyType? propertyType,
    String? propertySizeId,
    String? preferredDateLabel,
    PreferredTimeSlot? preferredTime,
    String? addressLabel,
    String? notes,
    bool? photosAdded,
    bool? videoAdded,
    String? customPropertyType,
  }) {
    return BookingDraft(
      serviceId: serviceId ?? this.serviceId,
      bookingType: bookingType ?? this.bookingType,
      propertyType: propertyType ?? this.propertyType,
      propertySizeId: propertySizeId ?? this.propertySizeId,
      preferredDateLabel: preferredDateLabel ?? this.preferredDateLabel,
      preferredTime: preferredTime ?? this.preferredTime,
      addressLabel: addressLabel ?? this.addressLabel,
      notes: notes ?? this.notes,
      photosAdded: photosAdded ?? this.photosAdded,
      videoAdded: videoAdded ?? this.videoAdded,
      customPropertyType: customPropertyType ?? this.customPropertyType,
    );
  }
}
