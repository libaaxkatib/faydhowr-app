import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/service_entities.dart';
import '../providers/services_providers.dart';

/// Related Services — small tappable chips linking to other services in
/// the same catalog. Navigates via `push`, same as the Services List.
class ServiceRelatedSection extends ConsumerWidget {
  const ServiceRelatedSection({required this.relatedServiceIds, super.key});

  final List<String> relatedServiceIds;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (relatedServiceIds.isEmpty) {
      return const SizedBox.shrink();
    }
    final Map<String, ServicePreview> byId = <String, ServicePreview>{
      for (final s in ref.watch(serviceCatalogProvider)) s.id: s,
    };
    final List<ServicePreview> related = <ServicePreview>[
      for (final id in relatedServiceIds)
        if (byId.containsKey(id)) byId[id]!,
    ];
    if (related.isEmpty) {
      return const SizedBox.shrink();
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Related Services'),
        SizedBox(
          height: 104,
          child: ListView.builder(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
            itemCount: related.length,
            itemBuilder: (BuildContext context, int index) {
              final ServicePreview service = related[index];
              return Padding(
                padding: const EdgeInsets.only(right: AppSpacing.space3),
                child: _RelatedServiceCard(
                  service: service,
                  onTap: () => context.push('/services/${service.id}'),
                ),
              );
            },
          ),
        ),
      ],
    );
  }
}

/// Larger horizontal related-service card: icon, name, starting price,
/// and a trailing tap-indicator arrow — same visual language as
/// [ServiceCard], scaled down for a horizontal scroller.
class _RelatedServiceCard extends StatelessWidget {
  const _RelatedServiceCard({required this.service, required this.onTap});

  final ServicePreview service;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: service.startingPrice == null
          ? service.name
          : '${service.name}, ${service.startingPrice}',
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          boxShadow: <BoxShadow>[
            BoxShadow(
              color: AppColors.primary.withValues(alpha: 0.10),
              blurRadius: 14,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: Material(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onTap,
            child: Container(
              width: 220,
              padding: const EdgeInsets.all(AppSpacing.space3),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: <Widget>[
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      color: AppColors.primary.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(AppRadius.md),
                    ),
                    alignment: Alignment.center,
                    child: Icon(service.icon, color: AppColors.primary, size: 22),
                  ),
                  const SizedBox(width: AppSpacing.space2),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Text(
                          service.name,
                          style: AppTypography.bodySmall.copyWith(fontWeight: FontWeight.w600),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                        if (service.startingPrice != null) ...<Widget>[
                          const SizedBox(height: AppSpacing.space1),
                          Text(
                            service.startingPrice!,
                            style: AppTypography.caption.copyWith(
                              color: AppColors.secondary,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                  const Icon(Icons.chevron_right, color: AppColors.textSecondary, size: 20),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
