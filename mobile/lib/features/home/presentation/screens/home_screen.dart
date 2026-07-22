import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_spacing.dart';
import '../providers/home_providers.dart';
import '../widgets/before_after_gallery_section.dart';
import '../widgets/contact_card_section.dart';
import '../widgets/customer_reviews_section.dart';
import '../widgets/faq_preview_section.dart';
import '../widgets/featured_services_section.dart';
import '../widgets/home_search_bar.dart';
import '../widgets/service_categories_section.dart';
import '../widgets/store_preview_section.dart';

/// Home screen (Home Premium UI Polish): assembles all eight sections in
/// the specified order, over mock data only. No business logic — CTA/action
/// callbacks are no-ops where the owning module doesn't exist yet.
///
/// No Hero Banner here — Welcome now owns the app's branding/hero moment
/// (Authentication UX Revision), so repeating "Welcome to Fayadhowr" /
/// "Book a Service" / "Browse Store" on Home would duplicate that
/// experience. Search is the first thing a returning user sees.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      body: SafeArea(
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              const SizedBox(height: AppSpacing.space3),
              const HomeSearchBar(),
              ServiceCategoriesSection(categories: ref.watch(serviceCategoriesProvider)),
              FeaturedServicesSection(services: ref.watch(featuredServicesProvider)),
              StorePreviewSection(products: ref.watch(storeProductsPreviewProvider)),
              BeforeAfterGallerySection(items: ref.watch(beforeAfterGalleryProvider)),
              CustomerReviewsSection(reviews: ref.watch(customerReviewsProvider)),
              FaqPreviewSection(items: ref.watch(faqItemsProvider)),
              ContactCardSection(contactInfo: ref.watch(contactInfoProvider)),
              const SizedBox(height: AppSpacing.space5),
            ],
          ),
        ),
      ),
    );
  }
}
