import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/service_entities.dart';

/// Small pill badge for a [ServiceMode] — One-Time in primary, Monthly
/// Contract in secondary, reinforcing the two-color brand identity.
///
/// [onDark] switches to a white-on-translucent treatment for use over the
/// gradient hero panel, where the normal low-alpha tinted background would
/// be nearly invisible against a similarly-colored backdrop.
class ServiceModeBadge extends StatelessWidget {
  const ServiceModeBadge({required this.mode, this.onDark = false, super.key});

  final ServiceMode mode;
  final bool onDark;

  @override
  Widget build(BuildContext context) {
    final Color accent = mode == ServiceMode.oneTime ? AppColors.primary : AppColors.secondary;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space2, vertical: 2),
      decoration: BoxDecoration(
        color: onDark ? AppColors.white.withValues(alpha: 0.20) : accent.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(AppRadius.sm),
      ),
      child: Text(
        mode.label,
        style: AppTypography.caption.copyWith(
          color: onDark ? AppColors.white : accent,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}
