import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/store_entities.dart';

/// Optional marketing badge — New · Best Seller · Popular · Limited Stock
/// (docs/02_SRS.md §8.4). Overlaid on the product image, distinct from
/// [AvailabilityBadge] (which sits in the text block) so the two never
/// compete for the same corner.
class ProductMarketingBadge extends StatelessWidget {
  const ProductMarketingBadge({required this.badge, super.key});

  final ProductBadge badge;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space2, vertical: 2),
      decoration: BoxDecoration(
        color: AppColors.primary,
        borderRadius: BorderRadius.circular(AppRadius.sm),
      ),
      child: Text(
        badge.label,
        style: AppTypography.caption.copyWith(color: AppColors.white, fontWeight: FontWeight.w600),
      ),
    );
  }
}
