import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/store_entities.dart';
import 'availability_badge.dart';

/// SKU, availability, optional tier pricing, and specifications — grouped
/// under one card, same idiom as Services' `ServiceInfoSection`.
class ProductInfoSection extends StatelessWidget {
  const ProductInfoSection({required this.detail, super.key});

  final ProductDetail detail;

  @override
  Widget build(BuildContext context) {
    final ProductPreview preview = detail.preview;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Product Information'),
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
                  children: <Widget>[
                    const Text('SKU', style: AppTypography.caption),
                    const Spacer(),
                    Text(detail.sku, style: AppTypography.bodySmall),
                  ],
                ),
                const SizedBox(height: AppSpacing.space2),
                Row(
                  children: <Widget>[
                    const Text('Availability', style: AppTypography.caption),
                    const Spacer(),
                    AvailabilityBadge(availability: preview.availability),
                  ],
                ),
                if (detail.priceTiers.isNotEmpty) ...<Widget>[
                  const Divider(height: AppSpacing.space5, color: AppColors.border),
                  const Text('Buy More & Save', style: AppTypography.caption),
                  const SizedBox(height: AppSpacing.space2),
                  for (int i = 0; i < detail.priceTiers.length; i++) ...<Widget>[
                    if (i > 0) const SizedBox(height: AppSpacing.space2),
                    Row(
                      children: <Widget>[
                        Text(detail.priceTiers[i].quantityLabel, style: AppTypography.bodySmall),
                        const Spacer(),
                        Text(
                          detail.priceTiers[i].priceLabel,
                          style: AppTypography.price.copyWith(color: AppColors.secondary),
                        ),
                      ],
                    ),
                  ],
                ],
                if (detail.specifications.isNotEmpty) ...<Widget>[
                  const Divider(height: AppSpacing.space5, color: AppColors.border),
                  const Text('Specifications', style: AppTypography.caption),
                  const SizedBox(height: AppSpacing.space2),
                  for (final String spec in detail.specifications)
                    Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.space2),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          const Icon(Icons.check_circle_outline, size: 16, color: AppColors.secondary),
                          const SizedBox(width: AppSpacing.space2),
                          Expanded(child: Text(spec, style: AppTypography.bodySmall)),
                        ],
                      ),
                    ),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}
