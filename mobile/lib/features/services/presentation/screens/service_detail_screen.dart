import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/service_entities.dart';
import '../providers/services_providers.dart';
import '../widgets/service_faq_section.dart';
import '../widgets/service_gallery_section.dart';
import '../widgets/service_hero_section.dart';
import '../widgets/service_how_it_works_section.dart';
import '../widgets/service_included_section.dart';
import '../widgets/service_info_section.dart';
import '../widgets/service_overview_section.dart';
import '../widgets/service_related_section.dart';
import '../widgets/service_reviews_section.dart';
import '../widgets/service_sticky_actions.dart';

/// Service Detail (Phase 1): full page layout only — Hero, Overview,
/// What's Included / Not Included, Before & After Gallery, How It Works,
/// Duration/Pricing/Coverage, FAQs, Reviews, Related Services, and a
/// sticky Book Now / Request Quotation action bar (buttons disabled until
/// the Booking Module exists).
class ServiceDetailScreen extends ConsumerWidget {
  const ServiceDetailScreen({required this.serviceId, super.key});

  final String serviceId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ServiceDetail? detail = ref.watch(serviceDetailsByIdProvider)[serviceId];

    if (detail == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Service')),
        body: Center(
          child: Text('Service not found', style: AppTypography.body.copyWith(color: AppColors.textSecondary)),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(title: Text(detail.preview.name)),
      body: Column(
        children: <Widget>[
          Expanded(
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  ServiceHeroSection(detail: detail),
                  ServiceOverviewSection(detail: detail),
                  ServiceIncludedSection(detail: detail),
                  ServiceGallerySection(items: detail.gallery),
                  ServiceHowItWorksSection(steps: detail.howItWorks),
                  ServiceInfoSection(detail: detail),
                  ServiceFaqSection(faqs: detail.faqs),
                  ServiceReviewsSection(reviews: detail.reviews),
                  ServiceRelatedSection(relatedServiceIds: detail.relatedServiceIds),
                  const SizedBox(height: AppSpacing.space6),
                ],
              ),
            ),
          ),
          ServiceStickyActions(
            bookNowState: ServiceActionState.enabled,
            onBookNow: () => context.push('/booking/${detail.preview.id}'),
          ),
        ],
      ),
    );
  }
}
