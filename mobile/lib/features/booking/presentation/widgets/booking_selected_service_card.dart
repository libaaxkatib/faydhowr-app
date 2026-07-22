import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/booking_entities.dart';
import '../providers/booking_providers.dart';

/// The pre-selected service the user is booking. Tapping the card lets the
/// user change their mind — Phase 1 keeps this simple by popping back to
/// the Service Detail page they came from (or further, to the Services
/// List) rather than adding an in-place service picker.
class BookingSelectedServiceCard extends ConsumerWidget {
  const BookingSelectedServiceCard({required this.serviceId, super.key});

  final String serviceId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final BookableService? service = ref.watch(bookingServicesByIdProvider)[serviceId];
    if (service == null) {
      return const SizedBox.shrink();
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.space3,
        AppSpacing.space3,
        AppSpacing.space3,
        0,
      ),
      child: Semantics(
        button: true,
        label: '${service.name}, selected. Change Service',
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
              onTap: () => context.pop(),
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.space3),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Row(
                      children: <Widget>[
                        Container(
                          width: 56,
                          height: 56,
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
                          child: Icon(service.icon, color: AppColors.primary, size: 26),
                        ),
                        const SizedBox(width: AppSpacing.space3),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: <Widget>[
                              Row(
                                children: <Widget>[
                                  Flexible(
                                    child: Text(
                                      service.name,
                                      style: AppTypography.heading3,
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                  ),
                                  const SizedBox(width: AppSpacing.space2),
                                  const Icon(Icons.check_circle, color: AppColors.success, size: 18),
                                ],
                              ),
                              const SizedBox(height: AppSpacing.space1),
                              Text(
                                service.shortDescription,
                                style: AppTypography.bodySmall.copyWith(color: AppColors.textSecondary),
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.space3),
                    // A real outlined action chip, not plain text — reads
                    // as an intentional CTA rather than an incidental link.
                    // On its own row (not squeezed beside the name/
                    // description) so neither ever has to truncate.
                    Align(
                      alignment: Alignment.centerRight,
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3, vertical: 6),
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(AppRadius.full),
                          border: Border.all(color: AppColors.secondary),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: <Widget>[
                            Text(
                              'Change Service',
                              style: AppTypography.caption.copyWith(
                                color: AppColors.secondary,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(width: 2),
                            const Icon(Icons.chevron_right, color: AppColors.secondary, size: 14),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
