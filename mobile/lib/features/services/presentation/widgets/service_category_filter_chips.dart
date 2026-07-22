import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/service_entities.dart';
import '../providers/services_providers.dart';

/// Horizontal category filter row: "All" plus each of the 9 approved
/// service names. Selecting a chip narrows the Services List to that
/// category; only one category can be selected at a time.
class ServiceCategoryFilterChips extends ConsumerWidget {
  const ServiceCategoryFilterChips({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final List<ServicePreview> services = ref.watch(serviceCatalogProvider);
    final String? selected = ref.watch(selectedServiceCategoryProvider);
    final List<String> options = <String>['All', for (final s in services) s.name];

    return SizedBox(
      height: 44,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
        itemCount: options.length,
        itemBuilder: (BuildContext context, int index) {
          final String label = options[index];
          final bool isSelected = (label == 'All' && selected == null) || label == selected;
          return Padding(
            padding: const EdgeInsets.only(right: AppSpacing.space2),
            child: ChoiceChip(
              label: Text(label),
              selected: isSelected,
              showCheckmark: false,
              onSelected: (_) => ref
                  .read(selectedServiceCategoryProvider.notifier)
                  .select(label == 'All' ? null : label),
              backgroundColor: AppColors.white,
              selectedColor: AppColors.secondary,
              labelStyle: AppTypography.bodySmall.copyWith(
                color: isSelected ? AppColors.white : AppColors.textPrimary,
                fontWeight: isSelected ? FontWeight.w600 : FontWeight.w400,
              ),
              shape: StadiumBorder(
                side: BorderSide(color: isSelected ? AppColors.secondary : AppColors.border),
              ),
            ),
          );
        },
      ),
    );
  }
}
