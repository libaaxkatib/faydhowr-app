import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_spacing.dart';

/// State a sticky action button can be presented in — mirrors
/// `ServiceActionState`/`BookingContinueState`. Request Quotation defaults
/// to [disabled]: the shared Quotation Module isn't built on mobile yet
/// (same "prepared, not wired" status Book Now had before Booking
/// shipped), even though `allow_optional_quotation` may be true for a
/// given product.
enum ProductActionState { disabled, enabled, loading }

/// Bottom sticky action bar — Add to Cart / Request Quotation
/// (docs/05_UI_UX_Design.md §4.6). Add to Cart is disabled when the
/// product is Out of Stock; Request Quotation only ever appears when the
/// product allows it.
class ProductStickyActions extends StatelessWidget {
  const ProductStickyActions({
    required this.addToCartState,
    required this.onAddToCart,
    this.quantitySelector,
    this.showRequestQuotation = false,
    this.onRequestQuotation,
    super.key,
  });

  final ProductActionState addToCartState;
  final VoidCallback? onAddToCart;

  /// Optional `− Qty +` control shown to the left of Add to Cart, matching
  /// the premium reference's single-row layout — still the same
  /// `QuantitySelector` widget/state Product Details already owned, just
  /// relocated into this bar rather than the content above it.
  final Widget? quantitySelector;
  final bool showRequestQuotation;
  final VoidCallback? onRequestQuotation;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: AppColors.white,
        border: const Border(top: BorderSide(color: AppColors.border)),
        boxShadow: <BoxShadow>[
          BoxShadow(
            color: AppColors.primary.withValues(alpha: 0.08),
            blurRadius: 16,
            offset: const Offset(0, -4),
          ),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.space3),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                children: <Widget>[
                  if (quantitySelector != null) ...<Widget>[
                    SizedBox(height: AppSpacing.controlHeight, child: quantitySelector),
                    const SizedBox(width: AppSpacing.space3),
                  ],
                  Expanded(
                    child: SizedBox(
                      height: AppSpacing.controlHeight,
                      child: _StickyActionButton(
                        label: 'Add to Cart',
                        state: addToCartState,
                        filled: true,
                        onPressed: onAddToCart,
                      ),
                    ),
                  ),
                ],
              ),
              if (showRequestQuotation) ...<Widget>[
                const SizedBox(height: AppSpacing.space2),
                SizedBox(
                  width: double.infinity,
                  child: _StickyActionButton(
                    label: 'Request Quotation',
                    state: ProductActionState.disabled,
                    filled: false,
                    onPressed: onRequestQuotation,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _StickyActionButton extends StatelessWidget {
  const _StickyActionButton({
    required this.label,
    required this.state,
    required this.filled,
    required this.onPressed,
  });

  final String label;
  final ProductActionState state;
  final bool filled;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final bool isLoading = state == ProductActionState.loading;
    final bool isEnabled = state == ProductActionState.enabled;
    final String semanticsSuffix = switch (state) {
      ProductActionState.disabled => 'currently unavailable',
      ProductActionState.enabled => 'available',
      ProductActionState.loading => 'loading',
    };

    final Widget child = isLoading
        ? SizedBox(
            width: 20,
            height: 20,
            child: CircularProgressIndicator(
              strokeWidth: 2.4,
              color: filled ? AppColors.white : AppColors.primary,
            ),
          )
        : Text(label);

    final VoidCallback? effectiveOnPressed = (isEnabled && !isLoading) ? onPressed : null;

    return Semantics(
      button: true,
      label: '$label, $semanticsSuffix',
      child: filled
          ? ElevatedButton(onPressed: effectiveOnPressed, child: child)
          : OutlinedButton(onPressed: effectiveOnPressed, child: child),
    );
  }
}
