import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/checkout_entities.dart';

/// Order Summary — Subtotal, Delivery Fee, Discount (placeholder row when
/// none applied), Total. Same card formula as the Orders Module's
/// `OrderSummaryCard` (own copy).
class CheckoutSummaryCard extends StatelessWidget {
  const CheckoutSummaryCard({required this.summary, super.key});

  final CheckoutSummary summary;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.space3),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        boxShadow: <BoxShadow>[
          BoxShadow(
            color: AppColors.primary.withValues(alpha: 0.10),
            blurRadius: 18,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          _row('Subtotal', summary.subtotal),
          const SizedBox(height: AppSpacing.space2),
          _row('Delivery Fee', summary.deliveryFee),
          const SizedBox(height: AppSpacing.space2),
          summary.discount > 0
              ? _row('Discount', -summary.discount, color: AppColors.success)
              : Row(
                  children: <Widget>[
                    const Text('Discount', style: AppTypography.bodySmall),
                    const Spacer(),
                    Text('No discount applied', style: AppTypography.caption),
                  ],
                ),
          const Divider(height: AppSpacing.space5, color: AppColors.border),
          Row(
            children: <Widget>[
              const Text('Total', style: AppTypography.subtitle),
              const Spacer(),
              Text(
                '\$${summary.total.toStringAsFixed(2)}',
                style: AppTypography.heading3.copyWith(color: AppColors.secondary),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _row(String label, double amount, {Color? color}) {
    final String sign = amount < 0 ? '-' : '';
    return Row(
      children: <Widget>[
        Text(label, style: AppTypography.bodySmall),
        const Spacer(),
        Text(
          '$sign\$${amount.abs().toStringAsFixed(2)}',
          style: AppTypography.bodySmall.copyWith(color: color, fontWeight: FontWeight.w600),
        ),
      ],
    );
  }
}
