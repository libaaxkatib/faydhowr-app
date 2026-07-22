import 'package:flutter/material.dart';

import '../domain/entities/service_entities.dart';

/// Mock data for the Services Module (Phase 1). No backend, no API.
///
/// [mockServices] is the official approved Fayadhowr Services Catalog V1 —
/// exactly these 9 services, exact names, exact service-mode matrix. Detail
/// body copy (overview/included/steps/FAQs/reviews) is representative
/// placeholder content, not final business content.

const List<ServicePreview> mockServices = <ServicePreview>[
  ServicePreview(
    id: 'deep-cleaning',
    name: 'Deep Cleaning',
    icon: Icons.cleaning_services_outlined,
    shortDescription: 'A thorough top-to-bottom clean for your home.',
    modes: <ServiceMode>[ServiceMode.oneTime, ServiceMode.monthlyContract],
    startingPrice: 'From \$45',
  ),
  ServicePreview(
    id: 'pest-control',
    name: 'Pest Control',
    icon: Icons.pest_control_outlined,
    shortDescription: 'Safe, effective treatment against common household pests.',
    modes: <ServiceMode>[ServiceMode.oneTime, ServiceMode.monthlyContract],
    startingPrice: 'From \$35',
  ),
  ServicePreview(
    id: 'carpet-cleaning',
    name: 'Carpet Cleaning',
    icon: Icons.texture_outlined,
    shortDescription: 'Professional carpet and rug cleaning.',
    modes: <ServiceMode>[ServiceMode.oneTime],
    startingPrice: 'From \$30',
  ),
  ServicePreview(
    id: 'sofa-chair-cleaning',
    name: 'Sofa & Chair Cleaning',
    icon: Icons.weekend_outlined,
    shortDescription: 'Deep upholstery cleaning for sofas and chairs.',
    modes: <ServiceMode>[ServiceMode.oneTime],
    startingPrice: 'From \$25',
  ),
  ServicePreview(
    id: 'post-construction-cleaning',
    name: 'Post Construction Cleaning',
    icon: Icons.construction_outlined,
    shortDescription: 'Full site clean-up after renovation or construction work.',
    modes: <ServiceMode>[ServiceMode.oneTime],
    startingPrice: 'From \$80',
  ),
  ServicePreview(
    id: 'window-cleaning',
    name: 'Window Cleaning',
    icon: Icons.window_outlined,
    shortDescription: 'Streak-free windows, inside and out.',
    modes: <ServiceMode>[ServiceMode.oneTime, ServiceMode.monthlyContract],
    startingPrice: 'From \$20',
  ),
  ServicePreview(
    id: 'fumigation-services',
    name: 'Fumigation Services',
    icon: Icons.air_outlined,
    shortDescription: 'Professional fumigation for full pest eradication.',
    modes: <ServiceMode>[ServiceMode.oneTime, ServiceMode.monthlyContract],
    startingPrice: 'From \$40',
  ),
  ServicePreview(
    id: 'housekeeper',
    name: 'Housekeeper',
    icon: Icons.person_outline,
    shortDescription: 'A dedicated housekeeper on a recurring monthly contract.',
    modes: <ServiceMode>[ServiceMode.monthlyContract],
    startingPrice: 'From \$150/mo',
  ),
  ServicePreview(
    id: 'monthly-cleaning-staff',
    name: 'Monthly Cleaning Staff',
    icon: Icons.groups_outlined,
    shortDescription: 'Dedicated cleaning staff on a recurring monthly contract.',
    modes: <ServiceMode>[ServiceMode.monthlyContract],
    startingPrice: 'From \$250/mo',
  ),
];

const List<String> _coverageAreas = <String>['Mogadishu', 'Hargeisa'];

const List<ServiceReview> _sharedReviews = <ServiceReview>[
  ServiceReview(
    name: 'Amina H.',
    rating: 5,
    reviewText: 'Excellent service, very professional team.',
    dateLabel: '2 weeks ago',
  ),
  ServiceReview(
    name: 'Yusuf A.',
    rating: 4,
    reviewText: 'Great job overall, will book again.',
    dateLabel: '1 month ago',
  ),
  ServiceReview(
    name: 'Hodan M.',
    rating: 5,
    reviewText: 'On time and thorough. Highly recommend.',
    dateLabel: '1 month ago',
  ),
];

List<ServiceGalleryItem> _gallery(String serviceName) => <ServiceGalleryItem>[
  ServiceGalleryItem(label: '$serviceName — Example 1'),
  ServiceGalleryItem(label: '$serviceName — Example 2'),
];

final List<ServiceDetail> mockServiceDetails = <ServiceDetail>[
  ServiceDetail(
    preview: mockServices[0],
    overview:
        'A comprehensive top-to-bottom clean covering every room — ideal for move-ins, spring cleaning, '
        'or a seasonal refresh.',
    included: const <String>[
      'All rooms dusted and wiped down',
      'Floors vacuumed and mopped',
      'Kitchen surfaces and appliance exteriors cleaned',
      'Bathrooms scrubbed and sanitized',
    ],
    notIncluded: const <String>['Laundry and ironing', 'Exterior window cleaning', 'Wall washing'],
    gallery: _gallery('Deep Cleaning'),
    howItWorks: const <ServiceStep>[
      ServiceStep(title: 'Book & Confirm', description: 'Choose a time that works for you and confirm your booking.'),
      ServiceStep(title: 'Team Arrives', description: 'A vetted cleaning team arrives fully equipped.'),
      ServiceStep(title: 'Walkthrough', description: 'Review the completed clean together before sign-off.'),
    ],
    estimatedDuration: '3–5 hours, depending on home size',
    pricingOptions: const <ServicePricingOption>[
      ServicePricingOption(mode: ServiceMode.oneTime, priceLabel: 'From \$45'),
      ServicePricingOption(mode: ServiceMode.monthlyContract, priceLabel: 'From \$150/mo'),
    ],
    coverageAreas: _coverageAreas,
    faqs: const <ServiceFaq>[
      ServiceFaq(
        question: 'Do I need to be home during the clean?',
        answer: 'No — many customers provide access instructions and are out during the visit.',
      ),
      ServiceFaq(
        question: 'What if I\'m not satisfied?',
        answer: 'Let us know within 24 hours and we\'ll arrange a re-clean of the affected areas.',
      ),
    ],
    reviews: _sharedReviews,
    relatedServiceIds: const <String>['post-construction-cleaning', 'carpet-cleaning'],
  ),
  ServiceDetail(
    preview: mockServices[1],
    overview: 'Targeted treatment to eliminate common household pests and help prevent them from returning.',
    included: const <String>['Inspection of affected areas', 'Safe, licensed pest treatment', 'Follow-up recommendations'],
    notIncluded: const <String>['Structural repairs', 'Full-property fumigation (see Fumigation Services)'],
    gallery: _gallery('Pest Control'),
    howItWorks: const <ServiceStep>[
      ServiceStep(title: 'Inspect', description: 'A technician inspects affected and at-risk areas.'),
      ServiceStep(title: 'Treat', description: 'Targeted, licensed treatment is applied safely.'),
      ServiceStep(title: 'Follow Up', description: 'Guidance on prevention and, if needed, a follow-up visit.'),
    ],
    estimatedDuration: '1–2 hours',
    pricingOptions: const <ServicePricingOption>[
      ServicePricingOption(mode: ServiceMode.oneTime, priceLabel: 'From \$35'),
      ServicePricingOption(mode: ServiceMode.monthlyContract, priceLabel: 'From \$120/mo'),
    ],
    coverageAreas: _coverageAreas,
    faqs: const <ServiceFaq>[
      ServiceFaq(
        question: 'Is the treatment safe for children and pets?',
        answer: 'We use licensed products and will advise on any precautions before treatment.',
      ),
      ServiceFaq(question: 'How soon will I see results?', answer: 'Most customers notice a reduction within a few days.'),
    ],
    reviews: _sharedReviews,
    relatedServiceIds: const <String>['fumigation-services'],
  ),
  ServiceDetail(
    preview: mockServices[2],
    overview: 'Deep steam cleaning that lifts dirt, stains, and odors from carpets and rugs.',
    included: const <String>['Pre-treatment of stains', 'Steam / deep-extraction cleaning', 'Deodorizing'],
    notIncluded: const <String>['Carpet repair or restretching', 'Upholstery cleaning (see Sofa & Chair Cleaning)'],
    gallery: _gallery('Carpet Cleaning'),
    howItWorks: const <ServiceStep>[
      ServiceStep(title: 'Assess', description: 'Carpets are inspected for stains and fabric type.'),
      ServiceStep(title: 'Deep Clean', description: 'Steam / deep-extraction cleaning lifts embedded dirt.'),
      ServiceStep(title: 'Dry & Finish', description: 'Carpets are left fresh, deodorized, and drying.'),
    ],
    estimatedDuration: '1–3 hours, depending on area',
    pricingOptions: const <ServicePricingOption>[
      ServicePricingOption(mode: ServiceMode.oneTime, priceLabel: 'From \$30'),
    ],
    coverageAreas: _coverageAreas,
    faqs: const <ServiceFaq>[
      ServiceFaq(question: 'How long until carpets are dry?', answer: 'Typically 4–6 hours with normal ventilation.'),
    ],
    reviews: _sharedReviews,
    relatedServiceIds: const <String>['sofa-chair-cleaning', 'deep-cleaning'],
  ),
  ServiceDetail(
    preview: mockServices[3],
    overview: 'Professional upholstery cleaning that removes dirt, dust, and stains from sofas and chairs.',
    included: const <String>['Fabric-safe pre-treatment', 'Deep upholstery cleaning', 'Deodorizing'],
    notIncluded: const <String>['Leather conditioning', 'Frame or structural repair'],
    gallery: _gallery('Sofa & Chair Cleaning'),
    howItWorks: const <ServiceStep>[
      ServiceStep(title: 'Assess Fabric', description: 'Fabric type is checked to choose a safe method.'),
      ServiceStep(title: 'Deep Clean', description: 'Upholstery is deep-cleaned and treated for stains.'),
      ServiceStep(title: 'Dry & Finish', description: 'Furniture is left fresh and deodorized.'),
    ],
    estimatedDuration: '1–2 hours',
    pricingOptions: const <ServicePricingOption>[
      ServicePricingOption(mode: ServiceMode.oneTime, priceLabel: 'From \$25'),
    ],
    coverageAreas: _coverageAreas,
    faqs: const <ServiceFaq>[
      ServiceFaq(question: 'Can you clean leather furniture?', answer: 'Yes — we use a leather-safe method on request.'),
    ],
    reviews: _sharedReviews,
    relatedServiceIds: const <String>['carpet-cleaning'],
  ),
  ServiceDetail(
    preview: mockServices[4],
    overview: 'A thorough site clean-up that clears dust, debris, and residue after renovation or construction work.',
    included: const <String>['Debris and dust removal', 'Surface and fixture wipe-down', 'Floor cleaning'],
    notIncluded: const <String>['Waste hauling / disposal', 'Paint or construction touch-ups'],
    gallery: _gallery('Post Construction Cleaning'),
    howItWorks: const <ServiceStep>[
      ServiceStep(title: 'Site Walkthrough', description: 'The team assesses the site and cleaning scope.'),
      ServiceStep(title: 'Deep Clean', description: 'Dust, debris, and residue are cleared throughout.'),
      ServiceStep(title: 'Final Check', description: 'A final walkthrough confirms the site is move-in ready.'),
    ],
    estimatedDuration: '4–8 hours, depending on site size',
    pricingOptions: const <ServicePricingOption>[
      ServicePricingOption(mode: ServiceMode.oneTime, priceLabel: 'From \$80'),
    ],
    coverageAreas: _coverageAreas,
    faqs: const <ServiceFaq>[
      ServiceFaq(question: 'Do you remove construction waste?', answer: 'We clean surfaces and floors; bulk waste hauling is not included.'),
    ],
    reviews: _sharedReviews,
    relatedServiceIds: const <String>['deep-cleaning', 'window-cleaning'],
  ),
  ServiceDetail(
    preview: mockServices[5],
    overview: 'Streak-free interior and exterior window cleaning for a clear, bright home.',
    included: const <String>['Interior and exterior glass cleaning', 'Sill and frame wipe-down', 'Screen cleaning'],
    notIncluded: const <String>['High-rise / above-ground-floor exterior access', 'Window repair'],
    gallery: _gallery('Window Cleaning'),
    howItWorks: const <ServiceStep>[
      ServiceStep(title: 'Prep', description: 'Sills, frames, and screens are prepped for cleaning.'),
      ServiceStep(title: 'Clean', description: 'Glass is cleaned streak-free, inside and out.'),
      ServiceStep(title: 'Inspect', description: 'A final pass checks for streaks and residue.'),
    ],
    estimatedDuration: '1–3 hours',
    pricingOptions: const <ServicePricingOption>[
      ServicePricingOption(mode: ServiceMode.oneTime, priceLabel: 'From \$20'),
      ServicePricingOption(mode: ServiceMode.monthlyContract, priceLabel: 'From \$70/mo'),
    ],
    coverageAreas: _coverageAreas,
    faqs: const <ServiceFaq>[
      ServiceFaq(question: 'Do you clean upper-floor exteriors?', answer: 'Ground-floor exterior access only for now.'),
    ],
    reviews: _sharedReviews,
    relatedServiceIds: const <String>['deep-cleaning'],
  ),
  ServiceDetail(
    preview: mockServices[6],
    overview: 'Professional fumigation treatment for full pest eradication, including hard-to-reach areas.',
    included: const <String>['Full-property fumigation', 'Pre-treatment area prep guidance', 'Post-treatment safety clearance'],
    notIncluded: const <String>['Structural pest-damage repair', 'Furniture replacement'],
    gallery: _gallery('Fumigation Services'),
    howItWorks: const <ServiceStep>[
      ServiceStep(title: 'Prepare', description: 'You\'ll receive guidance on preparing the space beforehand.'),
      ServiceStep(title: 'Fumigate', description: 'The property is fumigated by licensed technicians.'),
      ServiceStep(title: 'Clearance', description: 'A safety clearance is given before you re-enter.'),
    ],
    estimatedDuration: '2–4 hours, plus vacate time',
    pricingOptions: const <ServicePricingOption>[
      ServicePricingOption(mode: ServiceMode.oneTime, priceLabel: 'From \$40'),
      ServicePricingOption(mode: ServiceMode.monthlyContract, priceLabel: 'From \$130/mo'),
    ],
    coverageAreas: _coverageAreas,
    faqs: const <ServiceFaq>[
      ServiceFaq(question: 'How long do we need to vacate?', answer: 'Typically a few hours — your technician will confirm timing.'),
    ],
    reviews: _sharedReviews,
    relatedServiceIds: const <String>['pest-control'],
  ),
  ServiceDetail(
    preview: mockServices[7],
    overview: 'A dedicated housekeeper on a recurring monthly contract, keeping your home consistently clean and organized.',
    included: const <String>['Recurring scheduled visits', 'Routine cleaning and tidying', 'Consistent, vetted staff'],
    notIncluded: const <String>['One-time or single-visit bookings', 'Childcare or errands'],
    gallery: _gallery('Housekeeper'),
    howItWorks: const <ServiceStep>[
      ServiceStep(title: 'Request Staff', description: 'Tell us your household\'s needs and preferred schedule.'),
      ServiceStep(title: 'Meet & Schedule', description: 'We match you with a vetted housekeeper and set visit times.'),
      ServiceStep(title: 'Ongoing Service', description: 'Your housekeeper visits on the agreed recurring schedule.'),
    ],
    estimatedDuration: 'Ongoing — visit frequency set in your contract',
    pricingOptions: const <ServicePricingOption>[
      ServicePricingOption(mode: ServiceMode.monthlyContract, priceLabel: 'From \$150/mo'),
    ],
    coverageAreas: _coverageAreas,
    faqs: const <ServiceFaq>[
      ServiceFaq(question: 'Can I change my schedule later?', answer: 'Yes — contracts can be adjusted with notice.'),
    ],
    reviews: _sharedReviews,
    relatedServiceIds: const <String>['monthly-cleaning-staff', 'deep-cleaning'],
  ),
  ServiceDetail(
    preview: mockServices[8],
    overview: 'Dedicated cleaning staff for offices or larger properties, on a recurring monthly contract.',
    included: const <String>['Recurring scheduled visits', 'Dedicated trained staff', 'Consistent service standards'],
    notIncluded: const <String>['One-time or single-visit bookings', 'Facilities maintenance or repairs'],
    gallery: _gallery('Monthly Cleaning Staff'),
    howItWorks: const <ServiceStep>[
      ServiceStep(title: 'Request Staff', description: 'Tell us your property\'s size and cleaning needs.'),
      ServiceStep(title: 'Assign Team', description: 'We assign dedicated staff and confirm a visit schedule.'),
      ServiceStep(title: 'Ongoing Service', description: 'Your team keeps the property clean on a recurring basis.'),
    ],
    estimatedDuration: 'Ongoing — visit frequency set in your contract',
    pricingOptions: const <ServicePricingOption>[
      ServicePricingOption(mode: ServiceMode.monthlyContract, priceLabel: 'From \$250/mo'),
    ],
    coverageAreas: _coverageAreas,
    faqs: const <ServiceFaq>[
      ServiceFaq(question: 'Can staffing scale with our property size?', answer: 'Yes — staffing levels are set based on your property\'s needs.'),
    ],
    reviews: _sharedReviews,
    relatedServiceIds: const <String>['housekeeper'],
  ),
];
