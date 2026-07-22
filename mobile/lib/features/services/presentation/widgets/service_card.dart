import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/service_entities.dart';
import 'service_mode_badge.dart';

/// Services List row card. Entire card is tappable — no per-element
/// tap targets. Outer `Container` supplies the shadow only (no fill
/// color); `Material` is the direct background/ink ancestor, avoiding
/// the "ListTile background may be invisible" class of bug a colored
/// `DecoratedBox` ancestor would cause.
///
/// Description and price each reserve a fixed height regardless of actual
/// content length (1-line vs. 2-line descriptions, services with/without a
/// starting price), so every card in the list is the same height —
/// typography and layout are unchanged, only the reserved space around
/// them.
class ServiceCard extends StatelessWidget {
  const ServiceCard({required this.service, required this.onTap, super.key});

  final ServicePreview service;
  final VoidCallback onTap;

  static const double _descriptionHeight = 44;
  static const double _priceHeight = 22;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: '${service.name}. ${service.shortDescription}',
      child: Container(
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
        child: Material(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.space3),
              child: Row(
                // Centered (not top-aligned) so the image sits balanced
                // against the now fixed-height text column.
                crossAxisAlignment: CrossAxisAlignment.center,
                children: <Widget>[
                  // Placeholder image — no approved service image assets yet.
                  Container(
                    width: 76,
                    height: 76,
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                        colors: <Color>[
                          AppColors.primary.withValues(alpha: 0.08),
                          AppColors.secondary.withValues(alpha: 0.10),
                        ],
                      ),
                      borderRadius: BorderRadius.circular(AppRadius.md),
                    ),
                    alignment: Alignment.center,
                    child: Icon(service.icon, color: AppColors.primary, size: 30),
                  ),
                  const SizedBox(width: AppSpacing.space3),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Text(
                          service.name,
                          style: AppTypography.heading3,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: AppSpacing.space1),
                        SizedBox(
                          height: _descriptionHeight,
                          child: Text(
                            service.shortDescription,
                            style: AppTypography.bodySmall.copyWith(color: AppColors.textSecondary),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        const SizedBox(height: AppSpacing.space2),
                        Wrap(
                          spacing: AppSpacing.space1,
                          runSpacing: AppSpacing.space1,
                          children: <Widget>[
                            for (final mode in service.modes) ServiceModeBadge(mode: mode),
                          ],
                        ),
                        const SizedBox(height: AppSpacing.space2),
                        SizedBox(
                          height: _priceHeight,
                          child: service.startingPrice == null
                              ? null
                              : Text(
                                  service.startingPrice!,
                                  style: AppTypography.price.copyWith(color: AppColors.secondary),
                                ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: AppSpacing.space2),
                  const Icon(Icons.chevron_right, color: AppColors.textSecondary),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
