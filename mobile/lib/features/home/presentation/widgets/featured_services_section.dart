import 'package:flutter/material.dart';

import '../../../../core/theme/app_spacing.dart';
import '../../domain/entities/home_preview_entities.dart';
import 'featured_service_card.dart';
import 'home_section_header.dart';

/// Featured Services (Milestone F6 task 4): horizontally scrollable cards.
class FeaturedServicesSection extends StatelessWidget {
  const FeaturedServicesSection({required this.services, super.key});

  final List<FeaturedServicePreview> services;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const HomeSectionHeader(title: 'Featured Services'),
        SizedBox(
          height: 320,
          child: ListView.builder(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
            itemCount: services.length,
            itemBuilder: (BuildContext context, int index) => Padding(
              padding: const EdgeInsets.only(right: AppSpacing.space3),
              child: FeaturedServiceCard(service: services[index]),
            ),
          ),
        ),
      ],
    );
  }
}
