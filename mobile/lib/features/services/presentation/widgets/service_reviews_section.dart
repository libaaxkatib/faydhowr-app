import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/service_entities.dart';

class ServiceReviewsSection extends StatelessWidget {
  const ServiceReviewsSection({required this.reviews, super.key});

  final List<ServiceReview> reviews;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Customer Reviews'),
        SizedBox(
          height: 210,
          child: ListView.builder(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
            itemCount: reviews.length,
            itemBuilder: (BuildContext context, int index) => Padding(
              padding: const EdgeInsets.only(right: AppSpacing.space3),
              child: _ServiceReviewCard(review: reviews[index]),
            ),
          ),
        ),
      ],
    );
  }
}

class _ServiceReviewCard extends StatelessWidget {
  const _ServiceReviewCard({required this.review});

  final ServiceReview review;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label:
          '${review.name}, ${review.rating} out of 5 stars, ${review.dateLabel}: ${review.reviewText}',
      child: Container(
        width: 250,
        padding: const EdgeInsets.all(AppSpacing.space3),
        decoration: BoxDecoration(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          boxShadow: <BoxShadow>[
            BoxShadow(
              color: AppColors.primary.withValues(alpha: 0.10),
              blurRadius: 16,
              offset: const Offset(0, 5),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: <Widget>[
                CircleAvatar(
                  radius: 20,
                  backgroundColor: AppColors.primary.withValues(alpha: 0.10),
                  child: Text(
                    review.name.isNotEmpty ? review.name[0] : '?',
                    style: AppTypography.subtitle.copyWith(
                      color: AppColors.primary,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                const SizedBox(width: AppSpacing.space2),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      // Name is the primary identity — bolder than the
                      // rest of the card's body text.
                      Text(
                        review.name,
                        style: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w700),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      Text(review.dateLabel, style: AppTypography.caption),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.space3),
            Row(
              children: List<Widget>.generate(
                5,
                (int index) => Icon(
                  index < review.rating ? Icons.star : Icons.star_border,
                  color: AppColors.warning,
                  size: 16,
                ),
              ),
            ),
            const SizedBox(height: AppSpacing.space2),
            Text(
              review.reviewText,
              style: AppTypography.bodySmall.copyWith(color: AppColors.textSecondary),
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }
}
