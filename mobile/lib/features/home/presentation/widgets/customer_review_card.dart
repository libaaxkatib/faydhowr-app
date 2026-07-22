import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/home_preview_entities.dart';

/// Customer review card (Milestone F6 task 7). Mock data only.
class CustomerReviewCard extends StatelessWidget {
  const CustomerReviewCard({required this.review, super.key});

  final CustomerReviewPreview review;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: '${review.name}, ${review.rating} out of 5 stars: ${review.reviewText}',
      child: Container(
        width: 240,
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
              children: <Widget>[
                CircleAvatar(
                  radius: 18,
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
                  child: Text(
                    review.name,
                    style: AppTypography.bodySmall,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.space2),
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
