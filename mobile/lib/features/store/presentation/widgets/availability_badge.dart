import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/store_entities.dart';

/// Mandatory availability badge — In Stock · Low Stock · Out of Stock
/// (docs/05_UI_UX_Design.md §4.6, §5.4). Out-of-stock products remain
/// visible everywhere with this badge; never hidden.
class AvailabilityBadge extends StatelessWidget {
  const AvailabilityBadge({required this.availability, super.key});

  final AvailabilityStatus availability;

  Color get _color => switch (availability) {
    AvailabilityStatus.inStock => AppColors.success,
    AvailabilityStatus.lowStock => AppColors.warning,
    AvailabilityStatus.outOfStock => AppColors.error,
  };

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space2, vertical: 2),
      decoration: BoxDecoration(
        color: _color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(AppRadius.sm),
      ),
      child: Text(
        availability.label,
        style: AppTypography.caption.copyWith(color: _color, fontWeight: FontWeight.w600),
      ),
    );
  }
}
