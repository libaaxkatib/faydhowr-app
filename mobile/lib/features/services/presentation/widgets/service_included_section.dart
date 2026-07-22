import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/service_entities.dart';

/// "What's Included" and "What's Not Included", each as its own shadowed
/// checklist card.
class ServiceIncludedSection extends StatelessWidget {
  const ServiceIncludedSection({required this.detail, super.key});

  final ServiceDetail detail;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: "What's Included"),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: _ChecklistCard(
            items: detail.included,
            icon: Icons.check_circle,
            iconColor: AppColors.success,
          ),
        ),
        const SectionHeader(title: "What's Not Included"),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: _ChecklistCard(
            items: detail.notIncluded,
            icon: Icons.cancel,
            iconColor: AppColors.error,
          ),
        ),
      ],
    );
  }
}

class _ChecklistCard extends StatelessWidget {
  const _ChecklistCard({required this.items, required this.icon, required this.iconColor});

  final List<String> items;
  final IconData icon;
  final Color iconColor;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.space3),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        boxShadow: <BoxShadow>[
          BoxShadow(
            color: AppColors.primary.withValues(alpha: 0.08),
            blurRadius: 14,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        children: <Widget>[
          for (int i = 0; i < items.length; i++) ...<Widget>[
            if (i > 0) const SizedBox(height: AppSpacing.space2),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Icon(icon, size: 18, color: iconColor),
                const SizedBox(width: AppSpacing.space2),
                Expanded(child: Text(items[i], style: AppTypography.bodySmall)),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
