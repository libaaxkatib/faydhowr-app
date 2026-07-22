import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/order_entities.dart';

/// Order status chip — same tinted-pill language as the Store Module's
/// `AvailabilityBadge` (own copy: features must not import another
/// feature's presentation, docs/09_Flutter_Architecture.md §3.1).
class OrderStatusChip extends StatelessWidget {
  const OrderStatusChip({required this.status, super.key});

  final OrderStatus status;

  Color get _color => switch (status) {
    OrderStatus.pending => AppColors.warning,
    OrderStatus.confirmed => AppColors.secondary,
    OrderStatus.preparing => AppColors.secondary,
    OrderStatus.outForDelivery => AppColors.primary,
    OrderStatus.delivered => AppColors.success,
    OrderStatus.cancelled => AppColors.error,
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
        status.label,
        style: AppTypography.caption.copyWith(color: _color, fontWeight: FontWeight.w600),
      ),
    );
  }
}
