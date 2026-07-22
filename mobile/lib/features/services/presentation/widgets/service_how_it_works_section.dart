import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/service_entities.dart';

/// Numbered step-by-step walkthrough of how the service is delivered.
class ServiceHowItWorksSection extends StatelessWidget {
  const ServiceHowItWorksSection({required this.steps, super.key});

  final List<ServiceStep> steps;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'How It Works'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: Column(
            children: <Widget>[
              for (int i = 0; i < steps.length; i++) ...<Widget>[
                if (i > 0) const SizedBox(height: AppSpacing.space3),
                _StepRow(index: i + 1, step: steps[i]),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _StepRow extends StatelessWidget {
  const _StepRow({required this.index, required this.step});

  final int index;
  final ServiceStep step;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Container(
          width: 28,
          height: 28,
          decoration: const BoxDecoration(color: AppColors.primary, shape: BoxShape.circle),
          alignment: Alignment.center,
          child: Text(
            '$index',
            style: AppTypography.caption.copyWith(color: AppColors.white, fontWeight: FontWeight.w700),
          ),
        ),
        const SizedBox(width: AppSpacing.space3),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Text(step.title, style: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w600)),
              const SizedBox(height: AppSpacing.space1),
              Text(
                step.description,
                style: AppTypography.bodySmall.copyWith(color: AppColors.textSecondary),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
