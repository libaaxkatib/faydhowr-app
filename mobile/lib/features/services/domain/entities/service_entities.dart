import 'package:flutter/material.dart';

/// Plain preview/detail entities for the Services Module (Phase 1) — mock
/// data only, matching the Home Module's established pattern for a module
/// with no repository/backend yet.

/// A service can be booked One-Time, as a recurring Monthly Contract, or
/// both — the approved V1 service-mode matrix.
enum ServiceMode {
  oneTime('One-Time'),
  monthlyContract('Monthly Contract');

  const ServiceMode(this.label);

  final String label;
}

@immutable
class ServicePreview {
  const ServicePreview({
    required this.id,
    required this.name,
    required this.icon,
    required this.shortDescription,
    required this.modes,
    this.startingPrice,
  });

  final String id;
  final String name;
  final IconData icon;
  final String shortDescription;
  final List<ServiceMode> modes;

  /// Optional — the card's price line is not mandatory for every service.
  final String? startingPrice;
}

@immutable
class ServiceStep {
  const ServiceStep({required this.title, required this.description});

  final String title;
  final String description;
}

@immutable
class ServicePricingOption {
  const ServicePricingOption({required this.mode, required this.priceLabel});

  final ServiceMode mode;
  final String priceLabel;
}

@immutable
class ServiceFaq {
  const ServiceFaq({required this.question, required this.answer});

  final String question;
  final String answer;
}

@immutable
class ServiceReview {
  const ServiceReview({
    required this.name,
    required this.rating,
    required this.reviewText,
    required this.dateLabel,
  });

  final String name;

  /// 1–5.
  final int rating;
  final String reviewText;

  /// Display-ready relative date (e.g. "2 weeks ago") — mock content, not
  /// computed from a real timestamp.
  final String dateLabel;
}

@immutable
class ServiceGalleryItem {
  const ServiceGalleryItem({required this.label});

  final String label;
}

@immutable
class ServiceDetail {
  const ServiceDetail({
    required this.preview,
    required this.overview,
    required this.included,
    required this.notIncluded,
    required this.gallery,
    required this.howItWorks,
    required this.estimatedDuration,
    required this.pricingOptions,
    required this.coverageAreas,
    required this.faqs,
    required this.reviews,
    required this.relatedServiceIds,
  });

  final ServicePreview preview;
  final String overview;
  final List<String> included;
  final List<String> notIncluded;
  final List<ServiceGalleryItem> gallery;
  final List<ServiceStep> howItWorks;
  final String estimatedDuration;
  final List<ServicePricingOption> pricingOptions;
  final List<String> coverageAreas;
  final List<ServiceFaq> faqs;
  final List<ServiceReview> reviews;
  final List<String> relatedServiceIds;
}
