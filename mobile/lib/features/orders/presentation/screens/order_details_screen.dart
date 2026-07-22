import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/order_entities.dart';
import '../providers/orders_providers.dart';
import '../widgets/order_actions_bar.dart';
import '../widgets/order_product_row.dart';
import '../widgets/order_state_icon_box.dart';
import '../widgets/order_status_chip.dart';
import '../widgets/order_summary_card.dart';
import '../widgets/order_timeline.dart';

/// Part of ORDERS MODULE — FROZEN ✅ (see `OrdersListScreen`). Visual/UX
/// design approved final — no further UI changes without an explicit new
/// request.
///
/// Order Details (S-085, docs/05_UI_UX_Design.md §4.13, §13.2) — status
/// timeline, delivery address, products, order summary, and
/// status-dependent actions.
class OrderDetailsScreen extends ConsumerWidget {
  const OrderDetailsScreen({required this.orderId, super.key});

  final String orderId;

  static final DateFormat _dateFormat = DateFormat('MMM d, yyyy · h:mm a');

  Future<void> _confirmCancel(BuildContext context, WidgetRef ref, OrderDetail detail) async {
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        title: const Text('Cancel order?'),
        content: Text('Cancel order ${detail.preview.orderNumber}? This cannot be undone.'),
        actions: <Widget>[
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Keep Order')),
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('Cancel Order')),
        ],
      ),
    );
    if (confirmed ?? false) {
      await cancelOrder(ref, detail.preview.id);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<OrderDetail?> state = ref.watch(orderDetailProvider(orderId));

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(title: const Text('Order Details')),
      body: SafeArea(
        child: state.when(
          data: (OrderDetail? detail) {
            if (detail == null) {
              return Center(
                child: Text('Order not found', style: AppTypography.body.copyWith(color: AppColors.textSecondary)),
              );
            }
            return _OrderDetailsBody(
              detail: detail,
              onCancel: () => _confirmCancel(context, ref, detail),
              dateFormat: _dateFormat,
            );
          },
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (Object error, StackTrace _) => Center(
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.space4),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  const OrderStateIconBox(icon: Icons.error_outline, color: AppColors.error),
                  const SizedBox(height: AppSpacing.space3),
                  Text(
                    'Something went wrong loading this order.',
                    textAlign: TextAlign.center,
                    style: AppTypography.body.copyWith(color: AppColors.textSecondary),
                  ),
                  const SizedBox(height: AppSpacing.space3),
                  FilledButton(
                    onPressed: () => ref.invalidate(orderDetailProvider(orderId)),
                    child: const Text('Try Again'),
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

class _OrderDetailsBody extends StatelessWidget {
  const _OrderDetailsBody({required this.detail, required this.onCancel, required this.dateFormat});

  final OrderDetail detail;
  final VoidCallback onCancel;
  final DateFormat dateFormat;

  bool get _isActive => detail.preview.status != OrderStatus.delivered && detail.preview.status != OrderStatus.cancelled;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(AppSpacing.space3),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  detail.preview.orderNumber,
                  style: AppTypography.heading2,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              const SizedBox(width: AppSpacing.space2),
              OrderStatusChip(status: detail.preview.status),
            ],
          ),
          const SizedBox(height: AppSpacing.space1),
          Text('Placed ${dateFormat.format(detail.preview.placedAt)}', style: AppTypography.caption),
          const SizedBox(height: AppSpacing.space5),
          const Text('Status', style: AppTypography.subtitle),
          const SizedBox(height: AppSpacing.space3),
          OrderTimeline(status: detail.preview.status),
          const SizedBox(height: AppSpacing.space2),
          _Card(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                const Icon(Icons.location_on_outlined, size: 20, color: AppColors.secondary),
                const SizedBox(width: AppSpacing.space2),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      const Text('Delivery Address', style: AppTypography.caption),
                      const SizedBox(height: AppSpacing.space1),
                      Text(detail.deliveryAddress, style: AppTypography.bodySmall),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SectionHeader(title: 'Products'),
          _Card(
            child: Column(
              children: <Widget>[
                for (int i = 0; i < detail.items.length; i++) ...<Widget>[
                  if (i > 0) const Divider(height: AppSpacing.space3, color: AppColors.border),
                  OrderProductRow(item: detail.items[i]),
                ],
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.space4),
          OrderSummaryCard(detail: detail),
          const SizedBox(height: AppSpacing.space4),
          _Card(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                _infoRow('Payment Method', detail.paymentMethod.label),
                const SizedBox(height: AppSpacing.space2),
                _infoRow('Order Date', dateFormat.format(detail.preview.placedAt)),
                if (_isActive) ...<Widget>[
                  const SizedBox(height: AppSpacing.space2),
                  _infoRow('Expected Delivery', 'To be confirmed by our team'),
                ],
                if (detail.notes != null && detail.notes!.isNotEmpty) ...<Widget>[
                  const Divider(height: AppSpacing.space5, color: AppColors.border),
                  const Text('Notes', style: AppTypography.caption),
                  const SizedBox(height: AppSpacing.space1),
                  Text(detail.notes!, style: AppTypography.bodySmall),
                ],
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.space5),
          OrderActionsBar(status: detail.preview.status, onCancel: onCancel),
          const SizedBox(height: AppSpacing.space4),
        ],
      ),
    );
  }

  Widget _infoRow(String label, String value) {
    return Row(
      children: <Widget>[
        Text(label, style: AppTypography.caption),
        const Spacer(),
        Text(value, style: AppTypography.bodySmall),
      ],
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
