import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/service_entities.dart';
import 'service_mode_badge.dart';

/// Estimated Duration, Pricing Information, and Service Coverage — grouped
/// under one card as compact factual sub-blocks (same grouping idiom Home
/// used for its Contact Card: several related facts under one header).
class ServiceInfoSection extends StatelessWidget {
  const ServiceInfoSection({required this.detail, super.key});

  final ServiceDetail detail;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Duration, Pricing & Coverage'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: Container(
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
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    const Icon(Icons.schedule_outlined, color: AppColors.primary, size: 20),
                    const SizedBox(width: AppSpacing.space3),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          const Text('Estimated Duration', style: AppTypography.caption),
                          Text(detail.estimatedDuration, style: AppTypography.bodySmall),
                        ],
                      ),
                    ),
                  ],
                ),
                const Divider(height: AppSpacing.space5, color: AppColors.border),
                const Text('Pricing Information', style: AppTypography.caption),
                const SizedBox(height: AppSpacing.space2),
                for (int i = 0; i < detail.pricingOptions.length; i++) ...<Widget>[
                  if (i > 0) const SizedBox(height: AppSpacing.space2),
                  Row(
                    children: <Widget>[
                      ServiceModeBadge(mode: detail.pricingOptions[i].mode),
                      const Spacer(),
                      Text(
                        detail.pricingOptions[i].priceLabel,
                        style: AppTypography.price.copyWith(color: AppColors.secondary),
                      ),
                    ],
                  ),
                ],
                const Divider(height: AppSpacing.space5, color: AppColors.border),
                const Text('Service Coverage', style: AppTypography.caption),
                const SizedBox(height: AppSpacing.space2),
                Wrap(
                  spacing: AppSpacing.space2,
                  runSpacing: AppSpacing.space2,
                  children: <Widget>[
                    for (final area in detail.coverageAreas)
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: AppSpacing.space2,
                          vertical: AppSpacing.space1,
                        ),
                        decoration: BoxDecoration(
                          color: AppColors.secondary.withValues(alpha: 0.10),
                          borderRadius: BorderRadius.circular(AppRadius.sm),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: <Widget>[
                            const Icon(Icons.location_on_outlined, size: 14, color: AppColors.secondary),
                            const SizedBox(width: 4),
                            Text(
                              area,
                              style: AppTypography.caption.copyWith(
                                color: AppColors.secondary,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
