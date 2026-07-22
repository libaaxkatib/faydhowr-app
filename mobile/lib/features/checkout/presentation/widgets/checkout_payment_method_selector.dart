import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/checkout_entities.dart';
import '../providers/checkout_providers.dart';

/// Payment method selection — V1 methods only, EVC Plus default
/// (docs/05_UI_UX_Design.md §4.8, docs/06_API_Design.md §7.6). No card
/// fields anywhere — cards are deferred past V1.
class CheckoutPaymentMethodSelector extends ConsumerWidget {
  const CheckoutPaymentMethodSelector({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final CheckoutPaymentMethod selected = ref.watch(selectedPaymentMethodProvider);

    return Column(
      children: <Widget>[
        for (int i = 0; i < CheckoutPaymentMethod.values.length; i++) ...<Widget>[
          if (i > 0) const SizedBox(height: AppSpacing.space2),
          _PaymentMethodTile(
            method: CheckoutPaymentMethod.values[i],
            isSelected: CheckoutPaymentMethod.values[i] == selected,
            onTap: () => ref.read(selectedPaymentMethodProvider.notifier).select(CheckoutPaymentMethod.values[i]),
          ),
        ],
      ],
    );
  }
}

class _PaymentMethodTile extends StatelessWidget {
  const _PaymentMethodTile({required this.method, required this.isSelected, required this.onTap});

  final CheckoutPaymentMethod method;
  final bool isSelected;
  final VoidCallback onTap;

  IconData get _icon => switch (method) {
    CheckoutPaymentMethod.evcPlus || CheckoutPaymentMethod.eDahab => Icons.phone_android_outlined,
    CheckoutPaymentMethod.bankTransfer => Icons.account_balance_outlined,
    CheckoutPaymentMethod.cashOnDelivery => Icons.local_shipping_outlined,
  };

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: isSelected,
      label: method.label,
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.lg),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(AppSpacing.space3),
          decoration: BoxDecoration(
            color: AppColors.white,
            borderRadius: BorderRadius.circular(AppRadius.lg),
            border: Border.all(color: isSelected ? AppColors.primary : AppColors.border, width: isSelected ? 2 : 1),
          ),
          child: Row(
            children: <Widget>[
              Icon(_icon, color: AppColors.primary, size: 22),
              const SizedBox(width: AppSpacing.space3),
              Expanded(
                child: Text(method.label, style: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w600)),
              ),
              Icon(
                isSelected ? Icons.radio_button_checked : Icons.radio_button_unchecked,
                color: isSelected ? AppColors.primary : AppColors.textSecondary,
                size: 22,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
