import 'package:flutter/material.dart';

import '../domain/entities/home_preview_entities.dart';

/// Local mock data for the Home Module. No backend, no API.
///
/// [mockServiceCategories] is the official approved Fayadhowr Services
/// Catalog V1 (docs/02_SRS.md §7.2) — exactly these 9 services, this exact
/// naming. Do not add, remove, or rename entries here.

const List<ServiceCategoryPreview> mockServiceCategories = <ServiceCategoryPreview>[
  ServiceCategoryPreview(icon: Icons.cleaning_services_outlined, label: 'Deep Cleaning'),
  ServiceCategoryPreview(icon: Icons.pest_control_outlined, label: 'Pest Control'),
  ServiceCategoryPreview(icon: Icons.texture_outlined, label: 'Carpet Cleaning'),
  ServiceCategoryPreview(icon: Icons.weekend_outlined, label: 'Sofa & Chair Cleaning'),
  ServiceCategoryPreview(icon: Icons.construction_outlined, label: 'Post Construction Cleaning'),
  ServiceCategoryPreview(icon: Icons.window_outlined, label: 'Window Cleaning'),
  ServiceCategoryPreview(icon: Icons.air_outlined, label: 'Fumigation Services'),
  ServiceCategoryPreview(icon: Icons.person_outline, label: 'Housekeeper'),
  ServiceCategoryPreview(icon: Icons.groups_outlined, label: 'Monthly Cleaning Staff'),
];

const List<FeaturedServicePreview> mockFeaturedServices = <FeaturedServicePreview>[
  FeaturedServicePreview(
    name: 'Deep Cleaning',
    description: 'A thorough top-to-bottom clean for your home.',
    startingPrice: 'From \$45',
  ),
  FeaturedServicePreview(
    name: 'Carpet Cleaning',
    description: 'Professional carpet and rug cleaning.',
    startingPrice: 'From \$30',
  ),
  FeaturedServicePreview(
    name: 'Window Cleaning',
    description: 'Streak-free windows, inside and out.',
    startingPrice: 'From \$20',
  ),
  FeaturedServicePreview(
    name: 'Pest Control',
    description: 'Safe, effective pest treatment.',
    startingPrice: 'From \$35',
  ),
];

const List<StoreProductPreview> mockStoreProducts = <StoreProductPreview>[
  StoreProductPreview(name: 'All-Purpose Cleaner', price: '\$4.99', inStock: true),
  StoreProductPreview(name: 'Microfiber Cloth Set', price: '\$6.50', inStock: true),
  StoreProductPreview(name: 'Rubber Gloves', price: '\$2.99', inStock: false),
  StoreProductPreview(name: 'Air Freshener Spray', price: '\$3.75', inStock: true),
];

const List<BeforeAfterPreview> mockBeforeAfterGallery = <BeforeAfterPreview>[
  BeforeAfterPreview(label: 'Kitchen Deep Clean'),
  BeforeAfterPreview(label: 'Living Room Carpet'),
  BeforeAfterPreview(label: 'Office Windows'),
  BeforeAfterPreview(label: 'Bathroom Refresh'),
];

const List<CustomerReviewPreview> mockCustomerReviews = <CustomerReviewPreview>[
  CustomerReviewPreview(
    name: 'Amina H.',
    rating: 5,
    reviewText: 'Excellent service, very professional team.',
  ),
  CustomerReviewPreview(
    name: 'Yusuf A.',
    rating: 4,
    reviewText: 'Great job on the deep cleaning, will book again.',
  ),
  CustomerReviewPreview(
    name: 'Hodan M.',
    rating: 5,
    reviewText: 'On time and thorough. Highly recommend.',
  ),
];

const List<FaqPreview> mockFaqItems = <FaqPreview>[
  FaqPreview(
    question: 'How do I book a service?',
    answer: 'Choose a service, pick a time that works for you, and confirm your booking.',
  ),
  FaqPreview(
    question: 'What payment methods do you accept?',
    answer: 'EVC Plus, eDahab, bank transfer, and cash on service.',
  ),
  FaqPreview(
    question: 'Can I cancel a booking?',
    answer: 'Yes, cancellation is available according to our booking policy.',
  ),
  FaqPreview(
    question: 'Do you offer custom quotes?',
    answer: 'Yes — request a quotation for custom or larger jobs.',
  ),
];

const ContactInfoPreview mockContactInfo = ContactInfoPreview(
  phone: '+252 61 000 0000',
  whatsapp: '+252 61 000 0001',
  email: 'info@fayadhowr.example',
  location: 'Mogadishu, Somalia',
);
