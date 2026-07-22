import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/order_entities.dart';

/// Premium vertical status timeline (docs/05_UI_UX_Design.md §13.2 —
/// "Order Placed → Confirmed → Preparing → Out for Delivery →
/// Delivered"). Completed/current steps are highlighted; upcoming steps
/// stay neutral. [OrderStatus.cancelled] has no position in this happy
/// path, so it renders a distinct cancelled notice instead of a
/// frozen-mid-timeline guess.
class OrderTimeline extends StatelessWidget {
  const OrderTimeline({required this.status, super.key});

  final OrderStatus status;

  static const List<String> _steps = <String>[
    'Order Placed',
    'Confirmed',
    'Preparing',
    'Out for Delivery',
    'Delivered',
  ];

  @override
  Widget build(BuildContext context) {
    if (status == OrderStatus.cancelled) {
      return Container(
        width: double.infinity,
        padding: const EdgeInsets.all(AppSpacing.space3),
        decoration: BoxDecoration(
          color: AppColors.error.withValues(alpha: 0.06),
          borderRadius: BorderRadius.circular(AppRadius.lg),
          border: Border.all(color: AppColors.error.withValues(alpha: 0.24)),
        ),
        child: Row(
          children: <Widget>[
            const Icon(Icons.cancel_outlined, color: AppColors.error, size: 20),
            const SizedBox(width: AppSpacing.space2),
            Text(
              'This order was cancelled',
              style: AppTypography.bodySmall.copyWith(color: AppColors.error, fontWeight: FontWeight.w600),
            ),
          ],
        ),
      );
    }

    final int currentIndex = status.timelineIndex;

    return Column(
      children: <Widget>[
        for (int i = 0; i < _steps.length; i++)
          _TimelineStep(
            label: _steps[i],
            isCompleted: i < currentIndex,
            isCurrent: i == currentIndex,
            isLast: i == _steps.length - 1,
          ),
      ],
    );
  }
}

class _TimelineStep extends StatelessWidget {
  const _TimelineStep({
    required this.label,
    required this.isCompleted,
    required this.isCurrent,
    required this.isLast,
  });

  final String label;
  final bool isCompleted;
  final bool isCurrent;
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    final bool isReached = isCompleted || isCurrent;
    final Color dotColor = isReached ? AppColors.secondary : AppColors.border;
    final Color lineColor = isCompleted ? AppColors.secondary : AppColors.border;

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Column(
            children: <Widget>[
              Container(
                width: 22,
                height: 22,
                decoration: BoxDecoration(
                  color: isReached ? dotColor : AppColors.white,
                  shape: BoxShape.circle,
                  border: Border.all(color: dotColor, width: 2),
                ),
                alignment: Alignment.center,
                child: isReached
                    ? const Icon(Icons.check, size: 14, color: AppColors.white)
                    : null,
              ),
              if (!isLast)
                Expanded(
                  child: Container(width: 2, color: lineColor, margin: const EdgeInsets.symmetric(vertical: 2)),
                ),
            ],
          ),
          const SizedBox(width: AppSpacing.space3),
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.space4),
            child: Text(
              label,
              style: AppTypography.bodySmall.copyWith(
                color: isReached ? AppColors.textPrimary : AppColors.textSecondary,
                fontWeight: isCurrent ? FontWeight.w700 : FontWeight.w400,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
