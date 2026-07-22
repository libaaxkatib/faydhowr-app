import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/store_entities.dart';
import '../providers/store_providers.dart';
import 'quantity_selector.dart';

/// Cart line row (docs/05_UI_UX_Design.md §4.7, §10.1) — image, name, unit
/// price, quantity stepper, line total, Remove.
class CartItemRow extends ConsumerWidget {
  const CartItemRow({required this.product, required this.quantity, super.key});

  final ProductPreview product;
  final int quantity;

  Future<void> _confirmRemove(BuildContext context, WidgetRef ref) async {
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        title: const Text('Remove item?'),
        content: Text('Remove ${product.name} from your cart?'),
        actions: <Widget>[
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('Remove')),
        ],
      ),
    );
    if (confirmed ?? false) {
      ref.read(cartProvider.notifier).removeItem(product.id);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final double lineTotal = product.sellingPrice * quantity;

    return Container(
      padding: const EdgeInsets.all(AppSpacing.space3),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        boxShadow: <BoxShadow>[
          BoxShadow(
            color: AppColors.primary.withValues(alpha: 0.08),
            blurRadius: 14,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: <Widget>[
          Container(
            width: 64,
            height: 64,
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
            child: const Icon(Icons.image_outlined, color: AppColors.primary, size: 24),
          ),
          const SizedBox(width: AppSpacing.space3),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  product.name,
                  style: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w600),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: AppSpacing.space1),
                Text(product.priceLabel, style: AppTypography.caption),
                const SizedBox(height: AppSpacing.space2),
                QuantitySelector(
                  quantity: quantity,
                  maxQuantity: product.stockCount,
                  onChanged: (int next) => ref.read(cartProvider.notifier).updateQuantity(product.id, next),
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.space2),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(
                '\$${lineTotal.toStringAsFixed(2)}',
                style: AppTypography.price.copyWith(color: AppColors.secondary),
              ),
              const SizedBox(height: AppSpacing.space2),
              Semantics(
                button: true,
                label: 'Remove ${product.name} from cart',
                child: InkWell(
                  onTap: () => _confirmRemove(context, ref),
                  customBorder: const CircleBorder(),
                  child: const Padding(
                    padding: EdgeInsets.all(10),
                    child: Icon(Icons.delete_outline, size: 20, color: AppColors.textSecondary),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
