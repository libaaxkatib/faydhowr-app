import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/home_preview_entities.dart';
import 'home_section_header.dart';

/// Before & After Gallery (Milestone F6 task 6): horizontal gallery,
/// placeholder images only — no approved gallery assets exist yet.
class BeforeAfterGallerySection extends StatelessWidget {
  const BeforeAfterGallerySection({required this.items, super.key});

  final List<BeforeAfterPreview> items;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const HomeSectionHeader(title: 'Before & After Gallery'),
        SizedBox(
          height: 194,
          child: ListView.builder(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
            itemCount: items.length,
            itemBuilder: (BuildContext context, int index) {
              final BeforeAfterPreview item = items[index];
              return Padding(
                padding: const EdgeInsets.only(right: AppSpacing.space3),
                child: Semantics(
                  label: '${item.label}, before and after',
                  child: SizedBox(
                    width: 220,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Container(
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(AppRadius.lg),
                            boxShadow: <BoxShadow>[
                              BoxShadow(
                                color: AppColors.primary.withValues(alpha: 0.10),
                                blurRadius: 16,
                                offset: const Offset(0, 5),
                              ),
                            ],
                          ),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(AppRadius.lg),
                            child: SizedBox(
                              height: 140,
                              child: Row(
                                children: <Widget>[
                                  Expanded(
                                    child: Container(
                                      color: AppColors.primary.withValues(alpha: 0.10),
                                      alignment: Alignment.center,
                                      child: Text(
                                        'Before',
                                        style: AppTypography.caption.copyWith(
                                          color: AppColors.primary,
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                    ),
                                  ),
                                  Expanded(
                                    child: Container(
                                      color: AppColors.secondary.withValues(alpha: 0.12),
                                      alignment: Alignment.center,
                                      child: Text(
                                        'After',
                                        style: AppTypography.caption.copyWith(
                                          color: AppColors.secondary,
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(height: AppSpacing.space2),
                        Text(item.label, style: AppTypography.bodySmall),
                      ],
                    ),
                  ),
                ),
              );
            },
          ),
        ),
      ],
    );
  }
}
