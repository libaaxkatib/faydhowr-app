import 'package:flutter/material.dart';

/// Static content for the three onboarding pages.
///
/// Each page's hero is rendered by `onboarding_hero.dart`, which picks
/// `Image.asset` or `SvgPicture.asset` for [illustrationAsset] based on
/// its file extension. Page 1 uses the official Fayadhowr staff photo
/// (`assets/images/services_hero.jpg.jpg`); Page 2 uses the approved
/// Store product photo (`assets/images/Sale-of-Hygiene-Products.jpg`);
/// Page 3 uses the approved Booking flow image
/// (`assets/images/Booking.png`). All three pages are now on real,
/// approved artwork — swapping any of them later is a file-path change
/// only, no layout changes. Copy is generic and consistent with the
/// approved product vision (docs/02_SRS.md §1.3), not a new business claim.

/// A short "✓ Label" chip floated over/around the hero illustration.
@immutable
class OnboardingHighlight {
  const OnboardingHighlight({required this.label});

  final String label;
}

@immutable
class OnboardingPageData {
  const OnboardingPageData({
    required this.illustrationAsset,
    required this.title,
    required this.description,
    required this.highlights,
  });

  /// Path to this page's hero illustration SVG under
  /// `assets/illustrations/` (declared in `pubspec.yaml`).
  final String illustrationAsset;

  final String title;
  final String description;

  /// Small floating "✓ Label" info chips shown around the hero.
  final List<OnboardingHighlight> highlights;
}

const List<OnboardingPageData> onboardingPages = <OnboardingPageData>[
  // Page 1 — Services: professional cleaning for any property type.
  OnboardingPageData(
    illustrationAsset: 'assets/images/services_hero.jpg.jpg',
    title: 'Book Trusted Cleaning Services',
    description:
        'Reserve professional cleaning services for your home, office, apartment, villa, warehouse, hotel, '
        'school, or clinic in just a few taps.',
    highlights: <OnboardingHighlight>[
      OnboardingHighlight(label: 'Professional Team'),
      OnboardingHighlight(label: 'Trusted Service'),
      OnboardingHighlight(label: 'Same Day Service'),
    ],
  ),
  // Page 2 — Store: cleaning products and supplies.
  OnboardingPageData(
    illustrationAsset: 'assets/images/Sale-of-Hygiene-Products.jpg',
    title: 'Shop Professional Cleaning Products',
    description: 'Order trusted cleaning products and equipment directly from the Fayadhowr Store with fast delivery.',
    highlights: <OnboardingHighlight>[
      OnboardingHighlight(label: 'Premium Products'),
      OnboardingHighlight(label: 'Fast Delivery'),
      OnboardingHighlight(label: 'Best Quality'),
    ],
  ),
  // Page 3 — Booking & Quotation: the end-to-end booking flow.
  OnboardingPageData(
    illustrationAsset: 'assets/images/Booking.png',
    title: 'Book in Minutes',
    description:
        'Choose your service, upload photos, receive a quotation, and schedule your cleaning in one simple process.',
    highlights: <OnboardingHighlight>[
      OnboardingHighlight(label: 'Instant Booking'),
      OnboardingHighlight(label: 'Secure Process'),
      OnboardingHighlight(label: 'Easy Scheduling'),
    ],
  ),
];
