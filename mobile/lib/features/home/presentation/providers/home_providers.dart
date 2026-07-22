import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/home_mock_data.dart';
import '../../domain/entities/home_preview_entities.dart';

/// Home Module providers (Milestone F6). Plain `Provider`s exposing mock
/// data directly — no repository/data-source layer, since none is
/// genuinely required yet (no competing real/mock implementations to
/// abstract over). When Home gets a real backend, only these providers
/// change; widgets stay the same.

final serviceCategoriesProvider = Provider<List<ServiceCategoryPreview>>((ref) => mockServiceCategories);

final featuredServicesProvider = Provider<List<FeaturedServicePreview>>((ref) => mockFeaturedServices);

final storeProductsPreviewProvider = Provider<List<StoreProductPreview>>((ref) => mockStoreProducts);

final beforeAfterGalleryProvider = Provider<List<BeforeAfterPreview>>((ref) => mockBeforeAfterGallery);

final customerReviewsProvider = Provider<List<CustomerReviewPreview>>((ref) => mockCustomerReviews);

final faqItemsProvider = Provider<List<FaqPreview>>((ref) => mockFaqItems);

final contactInfoProvider = Provider<ContactInfoPreview>((ref) => mockContactInfo);

/// Search bar text — no real searching (out of scope); wired now so the
/// Search module can consume it later without touching this widget.
/// Hand-written `Notifier` (not `StateProvider`, unavailable in this
/// Riverpod version) — same pattern as `core/session/session_provider.dart`.
class HomeSearchQueryNotifier extends Notifier<String> {
  @override
  String build() => '';

  void update(String value) => state = value;
}

final homeSearchQueryProvider = NotifierProvider<HomeSearchQueryNotifier, String>(
  HomeSearchQueryNotifier.new,
);
