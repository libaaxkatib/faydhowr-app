import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/service_entities.dart';
import 'service_mode_badge.dart';

/// Service Detail hero — Dark Blue → Turquoise gradient panel matching the
/// app's established brand identity (Welcome Screen, Home section
/// accents). No approved hero photo asset exists yet for individual
/// services, so this is a bespoke gradient panel, not a stock/placeholder
/// icon drop-in.
class ServiceHeroSection extends StatelessWidget {
  const ServiceHeroSection({required this.detail, super.key});

  final ServiceDetail detail;

  @override
  Widget build(BuildContext context) {
    final ServicePreview preview = detail.preview;
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.fromLTRB(AppSpacing.space3, AppSpacing.space3, AppSpacing.space3, 0),
      // Slightly taller / more generous than a typical card for a more
      // premium hero moment — vertical padding only, layout unchanged.
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space4, vertical: AppSpacing.space5),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: <Color>[AppColors.primary, AppColors.secondary],
        ),
        borderRadius: BorderRadius.circular(AppRadius.xl),
        boxShadow: <BoxShadow>[
          BoxShadow(
            color: AppColors.primary.withValues(alpha: 0.20),
            blurRadius: 20,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Semantics(
            label: '${preview.name} illustration',
            image: true,
            child: Container(
              width: 56,
              height: 56,
              decoration: const BoxDecoration(color: AppColors.white, shape: BoxShape.circle),
              alignment: Alignment.center,
              child: Icon(preview.icon, color: AppColors.primary, size: 28),
            ),
          ),
          const SizedBox(height: AppSpacing.space4),
          Semantics(
            header: true,
            child: Text(preview.name, style: AppTypography.heading1.copyWith(color: AppColors.white)),
          ),
          const SizedBox(height: AppSpacing.space2),
          Text(
            preview.shortDescription,
            style: AppTypography.body.copyWith(color: AppColors.white.withValues(alpha: 0.85)),
          ),
          const SizedBox(height: AppSpacing.space4),
          Wrap(
            spacing: AppSpacing.space2,
            runSpacing: AppSpacing.space2,
            children: <Widget>[
              for (final mode in preview.modes) ServiceModeBadge(mode: mode, onDark: true),
            ],
          ),
        ],
      ),
    );
  }
}
