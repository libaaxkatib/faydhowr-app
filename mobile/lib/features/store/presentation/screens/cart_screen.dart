import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/store_entities.dart';
import '../providers/store_providers.dart';
import '../widgets/cart_item_row.dart';
import '../widgets/state_icon_box.dart';

/// Part of STORE MODULE — FROZEN ✅ (see `StoreScreen`). Visual/UX design
/// approved final — no further UI changes without an explicit new request.
///
/// Cart (S-040, docs/05_UI_UX_Design.md §4.7, §10.1) — review lines before
/// checkout. Checkout itself is out of this sprint's scope (Store Module
/// V1 — Cart Integration only); the button is shown in the same
/// "prepared, disabled" state the app already uses for not-yet-built flows
/// (e.g. Book Now before the Booking Module existed).
class CartScreen extends ConsumerWidget {
  const CartScreen({super.key});

  Future<void> _confirmClearCart(BuildContext context, WidgetRef ref) async {
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        title: const Text('Clear cart?'),
        content: const Text('Remove all items from your cart?'),
        actions: <Widget>[
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('Clear')),
        ],
      ),
    );
    if (confirmed ?? false) {
      ref.read(cartProvider.notifier).clear();
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final List<CartLine> lines = ref.watch(cartProvider);
    final Map<String, ProductPreview> catalog = <String, ProductPreview>{
      for (final ProductPreview p in ref.watch(productCatalogProvider)) p.id: p,
    };
    final double subtotal = ref.watch(cartSubtotalProvider);

    if (lines.isEmpty) {
      return Scaffold(
        appBar: AppBar(title: const Text('Cart')),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.space4),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                const StateIconBox(icon: Icons.shopping_cart_outlined),
                const SizedBox(height: AppSpacing.space3),
                Text(
                  'Your cart is empty',
                  style: AppTypography.heading3,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: AppSpacing.space2),
                Text(
                  'Browse the Store to add cleaning products and supplies to your cart.',
                  style: AppTypography.body.copyWith(color: AppColors.textSecondary),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: AppSpacing.space4),
                FilledButton(
                  onPressed: () => context.go('/store'),
                  child: const Text('Browse Store'),
                ),
              ],
            ),
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Cart'),
        actions: <Widget>[
          Semantics(
            button: true,
            label: 'Clear cart',
            child: IconButton(
              icon: const Icon(Icons.delete_outline),
              onPressed: () => _confirmClearCart(context, ref),
            ),
          ),
        ],
      ),
      body: Column(
        children: <Widget>[
          Expanded(
            child: ListView.separated(
              padding: const EdgeInsets.all(AppSpacing.space3),
              itemCount: lines.length,
              separatorBuilder: (BuildContext context, int index) => const SizedBox(height: AppSpacing.space3),
              itemBuilder: (BuildContext context, int index) {
                final CartLine line = lines[index];
                final ProductPreview? product = catalog[line.productId];
                if (product == null) {
                  return const SizedBox.shrink();
                }
                return CartItemRow(product: product, quantity: line.quantity);
              },
            ),
          ),
          _CartSummaryBar(subtotal: subtotal),
        ],
      ),
    );
  }
}

class _CartSummaryBar extends StatelessWidget {
  const _CartSummaryBar({required this.subtotal});

  final double subtotal;

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
                  const Text('Subtotal', style: AppTypography.subtitle),
                  const Spacer(),
                  Text(
                    '\$${subtotal.toStringAsFixed(2)}',
                    style: AppTypography.heading3.copyWith(color: AppColors.secondary),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.space3),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(AppSpacing.space3),
                decoration: BoxDecoration(
                  color: AppColors.background,
                  borderRadius: BorderRadius.circular(AppRadius.lg),
                ),
                child: Column(
                  children: <Widget>[
                    const Icon(Icons.auto_awesome_outlined, size: 28, color: AppColors.secondary),
                    const SizedBox(height: AppSpacing.space2),
                    Text('Checkout Coming Soon', style: AppTypography.heading3, textAlign: TextAlign.center),
                    const SizedBox(height: AppSpacing.space1),
                    Text(
                      'Checkout will be available soon. Stay tuned!',
                      style: AppTypography.caption,
                      textAlign: TextAlign.center,
                    ),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.space3),
              SizedBox(
                width: double.infinity,
                height: AppSpacing.controlHeight,
                child: Semantics(
                  button: true,
                  label: 'Checkout, currently unavailable',
                  child: ElevatedButton(onPressed: null, child: const Text('Checkout')),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
