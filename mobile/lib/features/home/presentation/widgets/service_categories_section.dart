import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/home_preview_entities.dart';
import 'home_section_header.dart';

/// Service Categories: the approved Fayadhowr V1 catalog (exactly 9
/// services) in a responsive grid — all services visible at once, no
/// horizontal scrolling. No navigation yet — tapping is a no-op.
class ServiceCategoriesSection extends StatelessWidget {
  const ServiceCategoriesSection({required this.categories, super.key});

  final List<ServiceCategoryPreview> categories;

  /// Mobile: fixed 3 columns (3×3 grid for the 9 approved services).
  /// Tablet/wider: scales up so cells never stretch beyond a comfortable
  /// icon-card width. Breakpoints follow Material's compact/medium/expanded
  /// window-size classes.
  static int _crossAxisCountFor(double width) {
    if (width < 600) {
      return 3;
    }
    if (width < 900) {
      return 5;
    }
    return 6;
  }

  // Tall enough for the icon plus a full 3-line label (the longest names —
  // "Post Construction Cleaning", "Monthly Cleaning Staff" — wrap to 3
  // lines at 3-column mobile width) with no truncation, ever.
  static const double _cardHeight = 152;

  // Fixed internal layout budget — every card reserves the exact same
  // vertical slots for top padding / icon / icon-label gap / label, so the
  // icon sits at an identical Y in every card and 2-line vs. 3-line labels
  // never throw off the card's visual rhythm. Must sum to `_cardHeight`.
  static const double _topPadding = AppSpacing.space1;
  static const double _iconSize = 68;
  static const double _iconLabelSpacing = AppSpacing.space2;
  static const double _labelAreaHeight = _cardHeight - _topPadding - _iconSize - _iconLabelSpacing;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const HomeSectionHeader(title: 'Service Categories'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: LayoutBuilder(
            builder: (BuildContext context, BoxConstraints constraints) {
              final int crossAxisCount = _crossAxisCountFor(constraints.maxWidth);
              final double cellWidth =
                  (constraints.maxWidth - (crossAxisCount - 1) * AppSpacing.space3) / crossAxisCount;
              return GridView.builder(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: categories.length,
                gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: crossAxisCount,
                  crossAxisSpacing: AppSpacing.space3,
                  mainAxisSpacing: AppSpacing.space3,
                  childAspectRatio: cellWidth / _cardHeight,
                ),
                itemBuilder: (BuildContext context, int index) {
                  final ServiceCategoryPreview category = categories[index];
                  return Semantics(
                    button: true,
                    label: category.label,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.center,
                      children: <Widget>[
                        const SizedBox(height: _topPadding),
                        Container(
                          width: _iconSize,
                          height: _iconSize,
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
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
                                color: AppColors.primary.withValues(alpha: 0.10),
                                blurRadius: 12,
                                offset: const Offset(0, 4),
                              ),
                            ],
                          ),
                          child: Icon(category.icon, color: AppColors.primary, size: 28),
                        ),
                        const SizedBox(height: _iconLabelSpacing),
                        // Fixed-height reserved label area, sized for the
                        // longest name (3 lines). Text is top-aligned
                        // (not centered) within it: centering would push a
                        // short 1-line label's first line lower than a
                        // 3-line label's, so the icon-to-text gap would
                        // vary card to card. Top-aligning keeps that gap
                        // identical everywhere, which is what actually
                        // makes the grid read as visually consistent — no
                        // truncation, every name wraps naturally.
                        SizedBox(
                          height: _labelAreaHeight,
                          child: Align(
                            alignment: Alignment.topCenter,
                            child: Text(
                              category.label,
                              textAlign: TextAlign.center,
                              maxLines: 3,
                              style: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w500),
                            ),
                          ),
                        ),
                      ],
                    ),
                  );
                },
              );
            },
          ),
        ),
      ],
    );
  }
}
