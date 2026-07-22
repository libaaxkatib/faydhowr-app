import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/order_entities.dart';

/// Order Summary — Subtotal, Delivery Fee, Discount (placeholder row when
/// none applied), Total (docs/03_Database_Design.md §3.7.1 `orders`:
/// subtotal_amount, delivery_fee, discount_amount, total_amount). Same
/// card formula as the Store Module's `ProductInfoSection`.
class OrderSummaryCard extends StatelessWidget {
  const OrderSummaryCard({required this.detail, super.key});

  final OrderDetail detail;

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
          _row('Subtotal', detail.subtotal),
          const SizedBox(height: AppSpacing.space2),
          _row('Delivery Fee', detail.deliveryFee),
          const SizedBox(height: AppSpacing.space2),
          detail.discount > 0
              ? _row('Discount', -detail.discount, color: AppColors.success)
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
                '\$${detail.total.toStringAsFixed(2)}',
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
