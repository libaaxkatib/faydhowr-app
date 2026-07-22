import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/checkout_entities.dart';

/// Part of CHECKOUT MODULE — FROZEN ✅ (see `CheckoutScreen`). Visual/UX
/// design approved final — no further UI changes without an explicit new
/// request.
///
/// Order Confirmation (S-042, docs/05_UI_UX_Design.md §4.8) — success
/// banner, Store Order Reference Number, totals, and next-step copy that
/// differs by payment method: prepaid methods await admin-verified
/// payment confirmation (docs/09_Flutter_Architecture.md §1.3A — V1 has
/// no live payment gateway); Cash on Delivery confirms immediately
/// (docs/06_API_Design.md §7.6A).
class OrderSuccessScreen extends StatelessWidget {
  const OrderSuccessScreen({required this.result, super.key});

  final PlacedOrderResult result;

  static final DateFormat _dateFormat = DateFormat('MMM d, yyyy · h:mm a');

  @override
  Widget build(BuildContext context) {
    final bool isPrepaid = result.paymentMethod.isPrepaid;

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(title: const Text('Order Confirmation'), automaticallyImplyLeading: false),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(AppSpacing.space3),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              const SizedBox(height: AppSpacing.space4),
              Center(
                child: Container(
                  width: 88,
                  height: 88,
                  decoration: BoxDecoration(color: AppColors.success.withValues(alpha: 0.10), shape: BoxShape.circle),
                  alignment: Alignment.center,
                  child: const Icon(Icons.check_circle_outline, color: AppColors.success, size: 48),
                ),
              ),
              const SizedBox(height: AppSpacing.space4),
              Text(
                isPrepaid ? 'Order Placed' : 'Order Confirmed',
                textAlign: TextAlign.center,
                style: AppTypography.heading2,
              ),
              const SizedBox(height: AppSpacing.space2),
              Text(
                isPrepaid
                    ? 'Your order has been placed and is awaiting payment confirmation.'
                    : 'Your order is confirmed. Payment will be collected on delivery.',
                textAlign: TextAlign.center,
                style: AppTypography.body.copyWith(color: AppColors.textSecondary),
              ),
              const SizedBox(height: AppSpacing.space5),
              Container(
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
                    _row('Order Reference', result.orderNumber),
                    const SizedBox(height: AppSpacing.space2),
                    _row('Status', isPrepaid ? 'Pending Payment' : 'Confirmed'),
                    const SizedBox(height: AppSpacing.space2),
                    _row('Payment Method', result.paymentMethod.label),
                    const SizedBox(height: AppSpacing.space2),
                    _row('Placed', _dateFormat.format(result.placedAt)),
                    const Divider(height: AppSpacing.space5, color: AppColors.border),
                    Row(
                      children: <Widget>[
                        const Text('Total', style: AppTypography.subtitle),
                        const Spacer(),
                        Text(
                          '\$${result.total.toStringAsFixed(2)}',
                          style: AppTypography.heading3.copyWith(color: AppColors.secondary),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.space5),
              SizedBox(
                height: AppSpacing.controlHeight,
                child: FilledButton(
                  onPressed: () => context.go('/orders'),
                  child: const Text('View My Orders'),
                ),
              ),
              const SizedBox(height: AppSpacing.space3),
              SizedBox(
                height: AppSpacing.controlHeight,
                child: OutlinedButton(
                  onPressed: () => context.go('/home'),
                  child: const Text('Back to Home'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _row(String label, String value) {
    return Row(
      children: <Widget>[
        Text(label, style: AppTypography.caption),
        const Spacer(),
        Text(value, style: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w600)),
      ],
    );
  }
}
