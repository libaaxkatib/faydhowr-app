import 'package:flutter/material.dart';

import '../domain/entities/booking_entities.dart';

/// Mock data for the Booking Module (Phase 1). No backend, no API.
///
/// [bookingServiceCatalog] mirrors the approved Fayadhowr Services Catalog
/// V1 (same 9 services, same names/icons as
/// `features/services/data/services_mock_data.dart`) — kept as an
/// independent copy per the feature-isolation dependency rule, not an
/// import of the Services Module.
const List<BookableService> bookingServiceCatalog = <BookableService>[
  BookableService(
    id: 'deep-cleaning',
    name: 'Deep Cleaning',
    icon: Icons.cleaning_services_outlined,
    shortDescription: 'A thorough top-to-bottom clean for your home.',
  ),
  BookableService(
    id: 'pest-control',
    name: 'Pest Control',
    icon: Icons.pest_control_outlined,
    shortDescription: 'Safe, effective treatment against common household pests.',
  ),
  BookableService(
    id: 'carpet-cleaning',
    name: 'Carpet Cleaning',
    icon: Icons.texture_outlined,
    shortDescription: 'Professional carpet and rug cleaning.',
  ),
  BookableService(
    id: 'sofa-chair-cleaning',
    name: 'Sofa & Chair Cleaning',
    icon: Icons.weekend_outlined,
    shortDescription: 'Deep upholstery cleaning for sofas and chairs.',
  ),
  BookableService(
    id: 'post-construction-cleaning',
    name: 'Post Construction Cleaning',
    icon: Icons.construction_outlined,
    shortDescription: 'Full site clean-up after renovation or construction work.',
  ),
  BookableService(
    id: 'window-cleaning',
    name: 'Window Cleaning',
    icon: Icons.window_outlined,
    shortDescription: 'Streak-free windows, inside and out.',
  ),
  BookableService(
    id: 'fumigation-services',
    name: 'Fumigation Services',
    icon: Icons.air_outlined,
    shortDescription: 'Professional fumigation for full pest eradication.',
  ),
  BookableService(
    id: 'housekeeper',
    name: 'Housekeeper',
    icon: Icons.person_outline,
    shortDescription: 'A dedicated housekeeper on a recurring monthly contract.',
  ),
  BookableService(
    id: 'monthly-cleaning-staff',
    name: 'Monthly Cleaning Staff',
    icon: Icons.groups_outlined,
    shortDescription: 'Dedicated cleaning staff on a recurring monthly contract.',
  ),
];

const List<PropertySizeOption> mockPropertySizes = <PropertySizeOption>[
  PropertySizeOption(id: 'studio', label: 'Studio'),
  PropertySizeOption(id: '1-bedroom', label: '1 Bedroom'),
  PropertySizeOption(id: '2-bedroom', label: '2 Bedroom'),
  PropertySizeOption(id: '3-bedroom', label: '3 Bedroom'),
  PropertySizeOption(id: '4-plus-bedroom', label: '4+ Bedroom'),
];

/// Mock date picker options — a static label list, not a real calendar.
const List<String> mockDateOptions = <String>[
  'Today',
  'Tomorrow',
  'In 2 Days',
  'In 3 Days',
  'Next Week',
];

const List<BookingAddressOption> mockAddressOptions = <BookingAddressOption>[
  BookingAddressOption(id: 'home', title: 'Home', subtitle: 'Hodan District, Mogadishu'),
  BookingAddressOption(id: 'office', title: 'Office', subtitle: 'Wadajir District, Mogadishu'),
  BookingAddressOption(id: 'other', title: 'Other', subtitle: 'Hargeisa'),
];
