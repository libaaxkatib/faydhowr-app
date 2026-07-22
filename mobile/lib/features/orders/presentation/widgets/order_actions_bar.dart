import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/order_entities.dart';

/// Status-dependent Order Details actions (docs §13.2 / this sprint's
/// brief). Only **Cancel Order** is wired to real (mock-repository) logic
/// — it is the sole action with a documented, enforceable rule
/// (docs/06_API_Design.md §7.6A: "Cancel: Allowed only while
/// `pending_payment`"). Contact / Track Progress / Track Delivery /
/// Download Receipt / Buy Again have no backend or cross-module
/// integration in this sprint's scope (Buy Again would need the Store
/// Module's cart, which is frozen and off-limits to import), so they are
/// shown as prepared, disabled placeholders — the same convention Book Now
/// used before the Booking Module existed.
class OrderActionsBar extends StatelessWidget {
  const OrderActionsBar({required this.status, required this.onCancel, super.key});

  final OrderStatus status;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    switch (status) {
      case OrderStatus.pending:
        return SizedBox(
          width: double.infinity,
          height: AppSpacing.controlHeight,
          child: Semantics(
            button: true,
            label: 'Cancel Order',
            child: OutlinedButton(
              onPressed: onCancel,
              style: OutlinedButton.styleFrom(foregroundColor: AppColors.error, side: const BorderSide(color: AppColors.error)),
              child: const Text('Cancel Order'),
            ),
          ),
        );
      case OrderStatus.confirmed:
        return const _DisabledAction(label: 'Contact Fayadhowr');
      case OrderStatus.preparing:
        return const _DisabledAction(label: 'Track Progress');
      case OrderStatus.outForDelivery:
        return const _DisabledAction(label: 'Track Delivery');
      case OrderStatus.delivered:
        return const Column(
          children: <Widget>[
            _DisabledAction(label: 'Buy Again', filled: true),
            SizedBox(height: AppSpacing.space2),
            _DisabledAction(label: 'Download Receipt'),
          ],
        );
      case OrderStatus.cancelled:
        return Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(vertical: AppSpacing.space3),
          alignment: Alignment.center,
          child: Text(
            'Order Cancelled',
            style: AppTypography.bodySmall.copyWith(color: AppColors.textSecondary, fontWeight: FontWeight.w600),
          ),
        );
    }
  }
}

class _DisabledAction extends StatelessWidget {
  const _DisabledAction({required this.label, this.filled = false});

  final String label;
  final bool filled;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      height: AppSpacing.controlHeight,
      child: Semantics(
        button: true,
        label: '$label, currently unavailable',
        child: filled
            ? ElevatedButton(onPressed: null, child: Text(label))
            : OutlinedButton(onPressed: null, child: Text(label)),
      ),
    );
  }
}
