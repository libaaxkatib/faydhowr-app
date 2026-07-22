import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/home_preview_entities.dart';
import 'home_section_header.dart';

/// FAQ Preview (Milestone F6 task 8): accordion list.
///
/// Uses `Column` rather than `ListView.builder` — the mock FAQ set is tiny
/// (~4 items) and `ExpansionTile` already manages its own dynamic,
/// per-item expanded/collapsed height, which doesn't benefit from
/// builder-based lazy layout the way the longer horizontal lists above do.
class FaqPreviewSection extends StatelessWidget {
  const FaqPreviewSection({required this.items, super.key});

  final List<FaqPreview> items;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const HomeSectionHeader(title: 'Frequently Asked Questions'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: Column(
            children: items
                .map(
                  (FaqPreview item) => Container(
                    margin: const EdgeInsets.only(bottom: AppSpacing.space3),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(AppRadius.lg),
                      boxShadow: <BoxShadow>[
                        BoxShadow(
                          color: AppColors.primary.withValues(alpha: 0.08),
                          blurRadius: 14,
                          offset: const Offset(0, 4),
                        ),
                      ],
                    ),
                    clipBehavior: Clip.antiAlias,
                    // Material (not just a colored DecoratedBox) so
                    // ExpansionTile's internal ListTile can paint its
                    // background/ink splashes correctly on this ancestor.
                    child: Material(
                      color: AppColors.white,
                      child: Theme(
                        data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
                        child: ExpansionTile(
                          iconColor: AppColors.primary,
                          collapsedIconColor: AppColors.textSecondary,
                          title: Text(
                            item.question,
                            style: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w600),
                          ),
                          childrenPadding: const EdgeInsets.fromLTRB(
                            AppSpacing.space3,
                            0,
                            AppSpacing.space3,
                            AppSpacing.space3,
                          ),
                          expandedAlignment: Alignment.centerLeft,
                          children: <Widget>[
                            Text(
                              item.answer,
                              style: AppTypography.bodySmall.copyWith(color: AppColors.textSecondary),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                )
                .toList(),
          ),
        ),
      ],
    );
  }
}
