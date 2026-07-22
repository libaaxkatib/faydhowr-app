import 'package:flutter/widgets.dart';

/// Plain preview entities for the Home Module (Milestone F6).
///
/// All Home content is mock/placeholder data (task scope: "No backend. No
/// API."). These types exist so mock data and widgets share one shape —
/// they are not domain models backed by any repository, since none exists
/// yet for Home.

@immutable
class ServiceCategoryPreview {
  const ServiceCategoryPreview({required this.icon, required this.label});

  final IconData icon;
  final String label;
}

@immutable
class FeaturedServicePreview {
  const FeaturedServicePreview({
    required this.name,
    required this.description,
    required this.startingPrice,
  });

  final String name;
  final String description;
  final String startingPrice;
}

@immutable
class StoreProductPreview {
  const StoreProductPreview({required this.name, required this.price, required this.inStock});

  final String name;
  final String price;
  final bool inStock;
}

@immutable
class BeforeAfterPreview {
  const BeforeAfterPreview({required this.label});

  final String label;
}

@immutable
class CustomerReviewPreview {
  const CustomerReviewPreview({
    required this.name,
    required this.rating,
    required this.reviewText,
  });

  final String name;

  /// 1–5.
  final int rating;
  final String reviewText;
}

@immutable
class FaqPreview {
  const FaqPreview({required this.question, required this.answer});

  final String question;
  final String answer;
}

@immutable
class ContactInfoPreview {
  const ContactInfoPreview({
    required this.phone,
    required this.whatsapp,
    required this.email,
    required this.location,
  });

  final String phone;
  final String whatsapp;
  final String email;
  final String location;
}
