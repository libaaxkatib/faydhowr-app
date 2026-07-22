import 'package:flutter/material.dart';

import '../../../../core/theme/app_spacing.dart';
import '../../domain/entities/home_preview_entities.dart';
import 'customer_review_card.dart';
import 'home_section_header.dart';

/// Customer Reviews (Milestone F6 task 7): horizontally scrollable cards.
class CustomerReviewsSection extends StatelessWidget {
  const CustomerReviewsSection({required this.reviews, super.key});

  final List<CustomerReviewPreview> reviews;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const HomeSectionHeader(title: 'Customer Reviews'),
        SizedBox(
          height: 190,
          child: ListView.builder(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
            itemCount: reviews.length,
            itemBuilder: (BuildContext context, int index) => Padding(
              padding: const EdgeInsets.only(right: AppSpacing.space3),
              child: CustomerReviewCard(review: reviews[index]),
            ),
          ),
        ),
      ],
    );
  }
}
