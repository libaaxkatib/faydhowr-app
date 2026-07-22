import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/checkout_entities.dart';
import '../providers/checkout_providers.dart';

/// Saved-address selector (docs/05_UI_UX_Design.md §4.8 — "saved address
/// selector; reuse saved — never re-ask full address if already
/// collected"). Same selectable-card idiom as the Booking Module's address
/// section (own copy — Booking is frozen and features must not import
/// another feature's presentation).
class CheckoutAddressSelector extends ConsumerWidget {
  const CheckoutAddressSelector({required this.addresses, super.key});

  final List<DeliveryAddressOption> addresses;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final String? selected = ref.watch(selectedAddressProvider);

    return Column(
      children: <Widget>[
        for (int i = 0; i < addresses.length; i++) ...<Widget>[
          if (i > 0) const SizedBox(height: AppSpacing.space2),
          _AddressTile(
            address: addresses[i],
            isSelected: addresses[i].id == selected,
            onTap: () => ref.read(selectedAddressProvider.notifier).select(addresses[i].id),
          ),
        ],
      ],
    );
  }
}

class _AddressTile extends StatelessWidget {
  const _AddressTile({required this.address, required this.isSelected, required this.onTap});

  final DeliveryAddressOption address;
  final bool isSelected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: isSelected,
      label: '${address.title}, ${address.subtitle}',
      child: Material(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          onTap: onTap,
          child: Container(
            padding: const EdgeInsets.all(AppSpacing.space3),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(AppRadius.lg),
              border: Border.all(color: isSelected ? AppColors.primary : AppColors.border, width: isSelected ? 2 : 1),
            ),
            child: Row(
              children: <Widget>[
                Icon(
                  isSelected ? Icons.radio_button_checked : Icons.radio_button_unchecked,
                  color: isSelected ? AppColors.primary : AppColors.textSecondary,
                  size: 22,
                ),
                const SizedBox(width: AppSpacing.space3),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(address.title, style: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w600)),
                      const SizedBox(height: AppSpacing.space1),
                      Text(address.subtitle, style: AppTypography.caption),
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
