import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/checkout_entities.dart';
import '../providers/checkout_providers.dart';
import '../widgets/checkout_address_selector.dart';
import '../widgets/checkout_line_item_row.dart';
import '../widgets/checkout_payment_method_selector.dart';
import '../widgets/checkout_summary_card.dart';

/// CHECKOUT MODULE — FROZEN ✅
///
/// Approved final (visual + UX). Do not make further UI/UX changes to
/// either Checkout screen or their widgets without an explicit new
/// request.
///
/// Checkout (S-041, docs/05_UI_UX_Design.md §4.8) — order summary,
/// saved-address selector, Contact Phone Number, payment method
/// selection, notes, and Place Order. Mock repository only — no real
/// Store Order is created, no payment is processed
/// (docs/09_Flutter_Architecture.md §1.3A: V1 has no live payment
/// gateway regardless).
class CheckoutScreen extends ConsumerWidget {
  const CheckoutScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<CheckoutSummary> summaryState = ref.watch(checkoutSummaryProvider);
    final AsyncValue<List<DeliveryAddressOption>> addressesState = ref.watch(savedAddressesProvider);
    final String? selectedAddress = ref.watch(selectedAddressProvider);
    final AsyncValue<PlacedOrderResult?> placeOrderState = ref.watch(placeOrderProvider);

    // Auto-select the first saved address once addresses load, so the
    // Place Order button isn't blocked on a manual tap for the common case.
    ref.listen<AsyncValue<List<DeliveryAddressOption>>>(savedAddressesProvider, (
      AsyncValue<List<DeliveryAddressOption>>? previous,
      AsyncValue<List<DeliveryAddressOption>> next,
    ) {
      next.whenData((List<DeliveryAddressOption> addresses) {
        if (addresses.isNotEmpty && ref.read(selectedAddressProvider) == null) {
          ref.read(selectedAddressProvider.notifier).select(addresses.first.id);
        }
      });
    });

    ref.listen<AsyncValue<PlacedOrderResult?>>(placeOrderProvider, (
      AsyncValue<PlacedOrderResult?>? previous,
      AsyncValue<PlacedOrderResult?> next,
    ) {
      final PlacedOrderResult? result = next.value;
      if (result != null) {
        context.push('/checkout/success', extra: result);
      }
    });

    final bool isReady = summaryState.hasValue && addressesState.hasValue;
    final bool isSubmitting = placeOrderState.isLoading;
    final bool canSubmit = isReady && selectedAddress != null && !isSubmitting;

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(title: const Text('Checkout')),
      body: SafeArea(
        child: !isReady
            ? summaryState.hasError || addressesState.hasError
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(AppSpacing.space4),
                      child: Text(
                        'Something went wrong loading checkout.',
                        style: AppTypography.body.copyWith(color: AppColors.textSecondary),
                      ),
                    ),
                  )
                : const Center(child: CircularProgressIndicator())
            : Column(
                children: <Widget>[
                  Expanded(
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.all(AppSpacing.space3),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          const Text('Delivery Address', style: AppTypography.subtitle),
                          const SizedBox(height: AppSpacing.space3),
                          CheckoutAddressSelector(addresses: addressesState.value!),
                          const SizedBox(height: AppSpacing.space5),
                          const Text('Products', style: AppTypography.subtitle),
                          const SizedBox(height: AppSpacing.space2),
                          _Card(
                            child: Column(
                              children: <Widget>[
                                for (int i = 0; i < summaryState.value!.items.length; i++) ...<Widget>[
                                  if (i > 0) const Divider(height: AppSpacing.space3, color: AppColors.border),
                                  CheckoutLineItemRow(item: summaryState.value!.items[i]),
                                ],
                              ],
                            ),
                          ),
                          const SizedBox(height: AppSpacing.space5),
                          CheckoutSummaryCard(summary: summaryState.value!),
                          const SizedBox(height: AppSpacing.space5),
                          const Text('Contact Phone Number', style: AppTypography.subtitle),
                          const SizedBox(height: AppSpacing.space2),
                          TextField(
                            keyboardType: TextInputType.phone,
                            onChanged: (String value) => ref.read(contactPhoneProvider.notifier).update(value),
                            decoration: const InputDecoration(hintText: 'For delivery coordination'),
                          ),
                          const SizedBox(height: AppSpacing.space5),
                          const Text('Payment Method', style: AppTypography.subtitle),
                          const SizedBox(height: AppSpacing.space3),
                          const CheckoutPaymentMethodSelector(),
                          const SizedBox(height: AppSpacing.space5),
                          const Text('Notes (Optional)', style: AppTypography.subtitle),
                          const SizedBox(height: AppSpacing.space2),
                          TextField(
                            maxLines: 3,
                            onChanged: (String value) => ref.read(checkoutNotesProvider.notifier).update(value),
                            decoration: const InputDecoration(hintText: 'Delivery instructions, gate code, etc.'),
                          ),
                          const SizedBox(height: AppSpacing.space4),
                        ],
                      ),
                    ),
                  ),
                  _PlaceOrderBar(canSubmit: canSubmit, isSubmitting: isSubmitting, onSubmit: () {
                    ref.read(placeOrderProvider.notifier).submit();
                  }),
                ],
              ),
      ),
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
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
      child: child,
    );
  }
}

class _PlaceOrderBar extends StatelessWidget {
  const _PlaceOrderBar({required this.canSubmit, required this.isSubmitting, required this.onSubmit});

  final bool canSubmit;
  final bool isSubmitting;
  final VoidCallback onSubmit;

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
          child: SizedBox(
            width: double.infinity,
            height: AppSpacing.controlHeight,
            child: Semantics(
              button: true,
              label: canSubmit ? 'Place Order' : 'Place Order, currently unavailable',
              child: ElevatedButton(
                onPressed: canSubmit ? onSubmit : null,
                child: AnimatedSwitcher(
                  duration: const Duration(milliseconds: 200),
                  transitionBuilder: (Widget child, Animation<double> animation) =>
                      FadeTransition(opacity: animation, child: ScaleTransition(scale: animation, child: child)),
                  child: isSubmitting
                      ? const SizedBox(
                          key: ValueKey<String>('loading'),
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(strokeWidth: 2.4, color: AppColors.white),
                        )
                      : const Text('Place Order', key: ValueKey<String>('label')),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
