import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/order_entities.dart';
import 'order_status_chip.dart';

/// Orders List card (docs/05_UI_UX_Design.md §4.13, §13.2) — order number,
/// date, status chip, total, item count, first-product thumbnail, and a
/// View Details action. Same premium card formula as the Store Module's
/// `ProductCard`/`ServiceCard` (white surface, `lg` radius, soft
/// primary-tinted shadow) — own copy, not a cross-feature import.
class OrderCard extends StatelessWidget {
  const OrderCard({required this.order, required this.onTap, super.key});

  final OrderPreview order;
  final VoidCallback onTap;

  static final DateFormat _dateFormat = DateFormat('MMM d, yyyy');

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: 'Order ${order.orderNumber}, ${order.status.label}, \$${order.totalAmount.toStringAsFixed(2)}',
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          boxShadow: <BoxShadow>[
            BoxShadow(
              color: AppColors.primary.withValues(alpha: 0.10),
              blurRadius: 16,
              offset: const Offset(0, 5),
            ),
          ],
        ),
        child: Material(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.space3),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Container(
                        width: 56,
                        height: 56,
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
                        child: const Icon(Icons.inventory_2_outlined, color: AppColors.primary, size: 24),
                      ),
                      const SizedBox(width: AppSpacing.space3),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            Text(
                              order.orderNumber,
                              style: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w600),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            const SizedBox(height: AppSpacing.space1),
                            Text(_dateFormat.format(order.placedAt), style: AppTypography.caption),
                          ],
                        ),
                      ),
                      const SizedBox(width: AppSpacing.space2),
                      OrderStatusChip(status: order.status),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.space3),
                  Row(
                    children: <Widget>[
                      Text(
                        '${order.itemCount} ${order.itemCount == 1 ? 'item' : 'items'}',
                        style: AppTypography.caption,
                      ),
                      const Spacer(),
                      Text(
                        '\$${order.totalAmount.toStringAsFixed(2)}',
                        style: AppTypography.price.copyWith(color: AppColors.secondary),
                      ),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.space3),
                  SizedBox(
                    width: double.infinity,
                    height: 40,
                    child: OutlinedButton(onPressed: onTap, child: const Text('View Details')),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
