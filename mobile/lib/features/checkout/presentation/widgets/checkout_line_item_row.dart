import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/checkout_entities.dart';

/// Checkout line item row — same visual language as the Orders Module's
/// `OrderProductRow` (own copy: features must not import another
/// feature's presentation, docs/09_Flutter_Architecture.md §3.1).
class CheckoutLineItemRow extends StatelessWidget {
  const CheckoutLineItemRow({required this.item, super.key});

  final CheckoutLineItem item;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.space2),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: <Widget>[
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: <Color>[
                  AppColors.primary.withValues(alpha: 0.08),
                  AppColors.secondary.withValues(alpha: 0.10),
                ],
              ),
              borderRadius: BorderRadius.circular(AppRadius.md),
            ),
            alignment: Alignment.center,
            child: const Icon(Icons.image_outlined, color: AppColors.primary, size: 20),
          ),
          const SizedBox(width: AppSpacing.space3),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  item.productName,
                  style: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w600),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: AppSpacing.space1),
                Text(
                  '\$${item.unitPrice.toStringAsFixed(2)} / ${item.unit} · Qty ${item.quantity}',
                  style: AppTypography.caption,
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.space2),
          Text(
            '\$${item.lineTotal.toStringAsFixed(2)}',
            style: AppTypography.price.copyWith(color: AppColors.secondary),
          ),
        ],
      ),
    );
  }
}
