import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/service_entities.dart';

/// Service-specific FAQ accordion. `Material` (not a colored `DecoratedBox`)
/// is the direct ancestor of `ExpansionTile` so its internal `ListTile` can
/// paint its background/ink correctly.
class ServiceFaqSection extends StatelessWidget {
  const ServiceFaqSection({required this.faqs, super.key});

  final List<ServiceFaq> faqs;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'FAQs'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: Column(
            children: <Widget>[
              for (final faq in faqs)
                Container(
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
                  child: Material(
                    color: AppColors.white,
                    child: Theme(
                      data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
                      child: ExpansionTile(
                        iconColor: AppColors.primary,
                        collapsedIconColor: AppColors.textSecondary,
                        title: Text(
                          faq.question,
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
                            faq.answer,
                            style: AppTypography.bodySmall.copyWith(color: AppColors.textSecondary),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
