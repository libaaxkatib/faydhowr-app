import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/home_preview_entities.dart';

/// Featured service card (Milestone F6 task 4). Book button is present
/// and enabled but performs no action — Booking is out of scope.
class FeaturedServiceCard extends StatelessWidget {
  const FeaturedServiceCard({required this.service, super.key});

  final FeaturedServicePreview service;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 224,
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
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          // Placeholder image — no approved service image assets yet.
          Container(
            height: 110,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: <Color>[
                  AppColors.primary.withValues(alpha: 0.06),
                  AppColors.secondary.withValues(alpha: 0.10),
                ],
              ),
            ),
            alignment: Alignment.center,
            child: const Icon(Icons.image_outlined, color: AppColors.primary, size: 32),
          ),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.space3),
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
                Text(
                  service.description,
                  style: AppTypography.bodySmall.copyWith(color: AppColors.textSecondary),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: AppSpacing.space2),
                Text(
                  service.startingPrice,
                  style: AppTypography.price.copyWith(color: AppColors.secondary),
                ),
                const SizedBox(height: AppSpacing.space2),
                SizedBox(
                  width: double.infinity,
                  child: Semantics(
                    button: true,
                    label: 'Book ${service.name}',
                    child: ElevatedButton(
                      onPressed: () {}, // No booking flow yet — out of scope.
                      child: const Text('Book'),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
