import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/order_entities.dart';
import '../providers/orders_providers.dart';
import '../widgets/order_card.dart';
import '../widgets/order_skeleton_card.dart';
import '../widgets/order_state_icon_box.dart';

/// ORDERS MODULE — FROZEN ✅
///
/// Approved final (visual + UX). Do not make further UI/UX changes to
/// either Orders screen or their widgets without an explicit new request.
///
/// Orders List (S-084, docs/05_UI_UX_Design.md §4.13, §13.2) — customer
/// order history: premium cards, pull-to-refresh, loading skeletons, empty
/// state, and a full-panel error/retry state. Auth required (reached from
/// Account) — matches `_protectedPathPrefixes` in `app/app_router.dart`.
class OrdersListScreen extends ConsumerWidget {
  const OrdersListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<List<OrderPreview>> ordersState = ref.watch(ordersListProvider);
    final OrdersListNotifier notifier = ref.watch(ordersListProvider.notifier);

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(title: const Text('My Orders')),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: notifier.refresh,
          child: ordersState.when(
            data: (List<OrderPreview> orders) => orders.isEmpty
                ? const _EmptyOrders()
                : ListView.separated(
                    padding: const EdgeInsets.all(AppSpacing.space3),
                    itemCount: orders.length,
                    separatorBuilder: (BuildContext context, int index) => const SizedBox(height: AppSpacing.space3),
                    itemBuilder: (BuildContext context, int index) => OrderCard(
                      order: orders[index],
                      onTap: () => context.push('/orders/${orders[index].id}'),
                    ),
                  ),
            loading: () => ListView.separated(
              padding: const EdgeInsets.all(AppSpacing.space3),
              itemCount: 4,
              separatorBuilder: (BuildContext context, int index) => const SizedBox(height: AppSpacing.space3),
              itemBuilder: (BuildContext context, int index) => const OrderSkeletonCard(),
            ),
            error: (Object error, StackTrace _) => _ErrorState(onRetry: notifier.refresh),
          ),
        ),
      ),
    );
  }
}

class _EmptyOrders extends StatelessWidget {
  const _EmptyOrders();

  @override
  Widget build(BuildContext context) {
    final Widget content = Padding(
      padding: const EdgeInsets.all(AppSpacing.space4),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const OrderStateIconBox(icon: Icons.receipt_long_outlined),
          const SizedBox(height: AppSpacing.space3),
          Text('No orders yet', style: AppTypography.heading3),
          const SizedBox(height: AppSpacing.space1),
          Text(
            'Your past Store orders will appear here once you place one.',
            textAlign: TextAlign.center,
            style: AppTypography.body.copyWith(color: AppColors.textSecondary),
          ),
          const SizedBox(height: AppSpacing.space4),
          FilledButton(
            onPressed: () => context.go('/store'),
            child: const Text('Start Shopping'),
          ),
        ],
      ),
    );

    return LayoutBuilder(
      builder: (BuildContext context, BoxConstraints constraints) {
        return SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          child: ConstrainedBox(
            constraints: BoxConstraints(minHeight: constraints.maxHeight),
            child: Center(child: content),
          ),
        );
      },
    );
  }
}

class _ErrorState extends StatelessWidget {
  const _ErrorState({required this.onRetry});

  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (BuildContext context, BoxConstraints constraints) {
        return SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          child: ConstrainedBox(
            constraints: BoxConstraints(minHeight: constraints.maxHeight),
            child: Center(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.space4),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    const OrderStateIconBox(icon: Icons.error_outline, color: AppColors.error),
                    const SizedBox(height: AppSpacing.space3),
                    Text(
                      'Something went wrong loading your orders.',
                      textAlign: TextAlign.center,
                      style: AppTypography.body.copyWith(color: AppColors.textSecondary),
                    ),
                    const SizedBox(height: AppSpacing.space3),
                    FilledButton(onPressed: onRetry, child: const Text('Try Again')),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}
