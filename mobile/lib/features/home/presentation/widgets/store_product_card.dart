import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/home_preview_entities.dart';

/// Store product preview card. Add to Cart is disabled — Cart/Store
/// business logic is out of scope.
class StoreProductCard extends StatelessWidget {
  const StoreProductCard({required this.product, super.key});

  final StoreProductPreview product;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 164,
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
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Stack(
            children: <Widget>[
              // Placeholder image — no approved product image assets yet.
              Container(
                height: 90,
                width: double.infinity,
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: <Color>[
                      AppColors.primary.withValues(alpha: 0.06),
                      AppColors.secondary.withValues(alpha: 0.10),
                    ],
                  ),
                ),
                alignment: Alignment.center,
                child: const Icon(Icons.image_outlined, color: AppColors.primary, size: 28),
              ),
              Positioned(
                top: AppSpacing.space1,
                left: AppSpacing.space1,
                child: _StockBadge(inStock: product.inStock),
              ),
            ],
          ),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.space2),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  product.name,
                  style: AppTypography.bodySmall,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: AppSpacing.space1),
                Text(product.price, style: AppTypography.price.copyWith(color: AppColors.secondary)),
                const SizedBox(height: AppSpacing.space2),
                SizedBox(
                  width: double.infinity,
                  child: Semantics(
                    button: true,
                    label: product.inStock
                        ? 'Add ${product.name} to cart, currently unavailable'
                        : '${product.name} is out of stock',
                    child: OutlinedButton(
                      // Inactive — Cart/Store business logic is out of scope.
                      onPressed: null,
                      child: const Text('Add to Cart'),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Stock indicator chip overlaid on the product image.
class _StockBadge extends StatelessWidget {
  const _StockBadge({required this.inStock});

  final bool inStock;

  @override
  Widget build(BuildContext context) {
    final Color color = inStock ? AppColors.success : AppColors.error;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space2, vertical: 2),
      decoration: BoxDecoration(
        color: AppColors.white.withValues(alpha: 0.92),
        borderRadius: BorderRadius.circular(AppRadius.sm),
      ),
      child: Text(
        inStock ? 'In Stock' : 'Out of Stock',
        style: AppTypography.caption.copyWith(color: color, fontWeight: FontWeight.w600),
      ),
    );
  }
}
