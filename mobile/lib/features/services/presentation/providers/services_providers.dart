import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/services_mock_data.dart';
import '../../domain/entities/service_entities.dart';

/// Mock-backed catalog providers for the Services Module (Phase 1) —
/// mirrors the Home Module's pattern of trivial `Provider`s wrapping
/// constant mock data, since there is no repository/backend yet.
final serviceCatalogProvider = Provider<List<ServicePreview>>((ref) => mockServices);

final serviceDetailsByIdProvider = Provider<Map<String, ServiceDetail>>(
  (ref) => <String, ServiceDetail>{
    for (final ServiceDetail detail in mockServiceDetails) detail.preview.id: detail,
  },
);

/// Free-text search across the Services List. Hand-written `Notifier` —
/// this Riverpod version has no `StateProvider`, same pattern as
/// `HomeSearchQueryNotifier`.
class ServicesSearchQueryNotifier extends Notifier<String> {
  @override
  String build() => '';

  void update(String value) => state = value;
}

final servicesSearchQueryProvider = NotifierProvider<ServicesSearchQueryNotifier, String>(
  ServicesSearchQueryNotifier.new,
);

/// Selected category filter chip — `null` means "All".
class SelectedServiceCategoryNotifier extends Notifier<String?> {
  @override
  String? build() => null;

  void select(String? category) => state = category;
}

final selectedServiceCategoryProvider = NotifierProvider<SelectedServiceCategoryNotifier, String?>(
  SelectedServiceCategoryNotifier.new,
);

/// Services List content after search + category filter are applied.
final filteredServicesProvider = Provider<List<ServicePreview>>((ref) {
  final String query = ref.watch(servicesSearchQueryProvider).trim().toLowerCase();
  final String? category = ref.watch(selectedServiceCategoryProvider);
  final List<ServicePreview> all = ref.watch(serviceCatalogProvider);

  return all.where((ServicePreview service) {
    final bool matchesCategory = category == null || service.name == category;
    final bool matchesQuery =
        query.isEmpty ||
        service.name.toLowerCase().contains(query) ||
        service.shortDescription.toLowerCase().contains(query);
    return matchesCategory && matchesQuery;
  }).toList();
});
