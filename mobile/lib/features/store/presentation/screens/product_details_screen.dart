import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/store_entities.dart';
import '../providers/store_providers.dart';
import '../widgets/availability_badge.dart';
import '../widgets/product_description_section.dart';
import '../widgets/product_gallery_view.dart';
import '../widgets/product_info_section.dart';
import '../widgets/product_sticky_actions.dart';
import '../widgets/quantity_selector.dart';
import '../widgets/related_products_section.dart';

/// Part of STORE MODULE — FROZEN ✅ (see `StoreScreen`). Visual/UX design
/// approved final — no further UI changes without an explicit new request.
///
/// Product Details (S-022, docs/05_UI_UX_Design.md §4.6) — full evaluation
/// before Add to Cart or optional Request Quotation.
class ProductDetailsScreen extends ConsumerStatefulWidget {
  const ProductDetailsScreen({required this.productId, super.key});

  final String productId;

  @override
  ConsumerState<ProductDetailsScreen> createState() => _ProductDetailsScreenState();
}

class _ProductDetailsScreenState extends ConsumerState<ProductDetailsScreen> {
  int _quantity = 1;

  void _handleAddToCart(ProductPreview preview) {
    ref.read(cartProvider.notifier).addToCart(preview.id, quantity: _quantity);
    ScaffoldMessenger.of(context).clearSnackBars();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('Added ${preview.name} to cart'),
        action: SnackBarAction(label: 'View Cart', onPressed: () => context.push('/cart')),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final ProductDetail? detail = ref.watch(productDetailsByIdProvider)[widget.productId];

    if (detail == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Product')),
        body: Center(
          child: Text('Product not found', style: AppTypography.body.copyWith(color: AppColors.textSecondary)),
        ),
      );
    }

    final ProductPreview preview = detail.preview;
    final bool outOfStock = preview.availability == AvailabilityStatus.outOfStock;

    return Scaffold(
      appBar: AppBar(title: Text(preview.name)),
      body: Column(
        children: <Widget>[
          Expanded(
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Padding(
                    padding: const EdgeInsets.all(AppSpacing.space3),
                    child: ProductGalleryView(
                      productId: preview.id,
                      gallery: detail.gallery,
                      badge: preview.badge,
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Text(preview.name, style: AppTypography.heading2),
                        const SizedBox(height: AppSpacing.space2),
                        Row(
                          children: <Widget>[
                            Text(
                              preview.priceLabel,
                              style: AppTypography.heading3.copyWith(color: AppColors.secondary),
                            ),
                            const SizedBox(width: AppSpacing.space3),
                            AvailabilityBadge(availability: preview.availability),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.space4),
                  ProductDescriptionSection(description: detail.description),
                  const SizedBox(height: AppSpacing.space2),
                  ProductInfoSection(detail: detail),
                  RelatedProductsSection(relatedProductIds: detail.relatedProductIds),
                  const SizedBox(height: AppSpacing.space6),
                ],
              ),
            ),
          ),
          ProductStickyActions(
            addToCartState: outOfStock ? ProductActionState.disabled : ProductActionState.enabled,
            onAddToCart: () => _handleAddToCart(preview),
            quantitySelector: outOfStock
                ? null
                : QuantitySelector(
                    quantity: _quantity,
                    maxQuantity: preview.stockCount,
                    onChanged: (int next) => setState(() => _quantity = next),
                  ),
            showRequestQuotation: detail.allowOptionalQuotation,
          ),
        ],
      ),
    );
  }
}
