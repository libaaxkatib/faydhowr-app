import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/store_entities.dart';
import '../providers/store_providers.dart';
import 'availability_badge.dart';
import 'product_marketing_badge.dart';

/// Canonical Product Card (docs/05_UI_UX_Design.md §5.4) — image, name,
/// mandatory visible Selling Price with unit, availability badge, optional
/// marketing badge, Add to Cart. Cost Price is never modeled or shown. No
/// Favorite (heart) icon — product favorites are deferred past V1. Reused
/// on the Store Catalog grid and the Product Details "Related Products"
/// strip (callers control width by wrapping in a `SizedBox`).
class ProductCard extends ConsumerWidget {
  const ProductCard({required this.product, required this.onTap, super.key});

  final ProductPreview product;
  final VoidCallback onTap;

  static const double _nameHeight = 20;

  void _handleAddToCart(BuildContext context, WidgetRef ref) {
    ref.read(cartProvider.notifier).addToCart(product.id);
    ScaffoldMessenger.of(context).clearSnackBars();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('Added ${product.name} to cart'),
        action: SnackBarAction(label: 'View Cart', onPressed: () => context.push('/cart')),
      ),
    );
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final bool outOfStock = product.availability == AvailabilityStatus.outOfStock;

    return Semantics(
      button: true,
      label: '${product.name}. ${product.priceLabel}. ${product.availability.label}',
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
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Stack(
                  children: <Widget>[
                    AspectRatio(
                      aspectRatio: 1.3,
                      child: DecoratedBox(
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
                        child: const Center(
                          child: Icon(Icons.image_outlined, color: AppColors.primary, size: 28),
                        ),
                      ),
                    ),
                    if (product.badge != null)
                      Positioned(
                        top: AppSpacing.space1,
                        left: AppSpacing.space1,
                        child: ProductMarketingBadge(badge: product.badge!),
                      ),
                  ],
                ),
                Padding(
                  padding: const EdgeInsets.all(AppSpacing.space2),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      SizedBox(
                        height: _nameHeight,
                        child: Text(
                          product.name,
                          style: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w600),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      const SizedBox(height: AppSpacing.space1),
                      Text(
                        product.priceLabel,
                        style: AppTypography.price.copyWith(color: AppColors.secondary),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: AppSpacing.space1),
                      AvailabilityBadge(availability: product.availability),
                      const SizedBox(height: AppSpacing.space2),
                      SizedBox(
                        width: double.infinity,
                        height: 40,
                        child: Semantics(
                          button: true,
                          label: outOfStock ? '${product.name} is out of stock' : 'Add ${product.name} to cart',
                          child: ElevatedButton.icon(
                            onPressed: outOfStock ? null : () => _handleAddToCart(context, ref),
                            style: ElevatedButton.styleFrom(
                              padding: EdgeInsets.zero,
                              backgroundColor: AppColors.primary,
                              disabledBackgroundColor: AppColors.border,
                              elevation: 0,
                              textStyle: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w600),
                            ),
                            icon: Icon(
                              outOfStock ? Icons.block : Icons.shopping_cart_outlined,
                              size: 16,
                              color: outOfStock ? AppColors.textSecondary : AppColors.white,
                            ),
                            label: Text(
                              outOfStock ? 'Out of Stock' : 'Add to Cart',
                              style: TextStyle(color: outOfStock ? AppColors.textSecondary : AppColors.white),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
