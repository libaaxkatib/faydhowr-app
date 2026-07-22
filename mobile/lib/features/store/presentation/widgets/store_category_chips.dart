import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/store_entities.dart';
import '../providers/store_providers.dart';

/// Horizontal category filter row: "All Products" plus each of the 5
/// approved V1 Store categories (docs/02_SRS.md §8.1). Selecting a tile
/// narrows the catalog to that category; only one category can be selected
/// at a time.
///
/// Icon-tile presentation (not pill chips) mirrors the tinted-icon-box
/// language `ServiceCategoriesSection` already established on Home — same
/// component family, restyled here only for the Store Module's premium
/// visual pass, with an added selected/filled state chips never needed.
class StoreCategoryChips extends ConsumerWidget {
  const StoreCategoryChips({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final List<ProductCategoryPreview> categories = ref.watch(productCategoryCatalogProvider);
    final String? selected = ref.watch(selectedProductCategoryProvider);

    return SizedBox(
      height: 100,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
        itemCount: categories.length + 1,
        itemBuilder: (BuildContext context, int index) {
          final bool isAll = index == 0;
          final String label = isAll ? 'All Products' : categories[index - 1].name;
          final IconData icon = isAll ? Icons.apps_rounded : categories[index - 1].icon;
          final String? categoryId = isAll ? null : categories[index - 1].id;
          final bool isSelected = selected == categoryId;

          return Padding(
            padding: const EdgeInsets.only(right: AppSpacing.space2),
            child: Semantics(
              button: true,
              selected: isSelected,
              label: label,
              child: InkWell(
                borderRadius: BorderRadius.circular(AppRadius.lg),
                onTap: () => ref.read(selectedProductCategoryProvider.notifier).select(categoryId),
                child: SizedBox(
                  width: 84,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        curve: Curves.easeOut,
                        width: 60,
                        height: 60,
                        decoration: BoxDecoration(
                          color: isSelected ? AppColors.secondary : null,
                          gradient: isSelected
                              ? null
                              : LinearGradient(
                                  begin: Alignment.topLeft,
                                  end: Alignment.bottomRight,
                                  colors: <Color>[
                                    AppColors.primary.withValues(alpha: 0.08),
                                    AppColors.secondary.withValues(alpha: 0.10),
                                  ],
                                ),
                          borderRadius: BorderRadius.circular(AppRadius.lg),
                          boxShadow: <BoxShadow>[
                            BoxShadow(
                              color: (isSelected ? AppColors.secondary : AppColors.primary).withValues(alpha: 0.16),
                              blurRadius: 12,
                              offset: const Offset(0, 4),
                            ),
                          ],
                        ),
                        child: Icon(icon, color: isSelected ? AppColors.white : AppColors.primary, size: 26),
                      ),
                      const SizedBox(height: AppSpacing.space1),
                      Text(
                        label,
                        textAlign: TextAlign.center,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.caption.copyWith(
                          color: isSelected ? AppColors.textPrimary : AppColors.textSecondary,
                          fontWeight: isSelected ? FontWeight.w600 : FontWeight.w400,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}
