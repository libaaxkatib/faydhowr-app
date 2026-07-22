import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/service_entities.dart';
import '../providers/services_providers.dart';
import '../widgets/service_card.dart';
import '../widgets/service_category_filter_chips.dart';
import '../widgets/service_search_bar.dart';

/// Services Module Phase 1: browse, search, and filter the approved V1
/// catalog, then drill into a Service Detail screen. No booking — cards
/// only navigate, they don't perform any business action.
class ServicesListScreen extends ConsumerWidget {
  const ServicesListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final List<ServicePreview> services = ref.watch(filteredServicesProvider);

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: <Widget>[
            const SizedBox(height: AppSpacing.space3),
            const ServiceSearchBar(),
            const SizedBox(height: AppSpacing.space2),
            const ServiceCategoryFilterChips(),
            Expanded(
              child: services.isEmpty
                  ? const _NoResults()
                  : ListView.builder(
                      padding: const EdgeInsets.fromLTRB(
                        AppSpacing.space3,
                        AppSpacing.space2,
                        AppSpacing.space3,
                        AppSpacing.space5,
                      ),
                      itemCount: services.length,
                      itemBuilder: (BuildContext context, int index) {
                        final ServicePreview service = services[index];
                        return Padding(
                          padding: const EdgeInsets.only(bottom: AppSpacing.space4),
                          child: ServiceCard(
                            service: service,
                            onTap: () => context.push('/services/${service.id}'),
                          ),
                        );
                      },
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

class _NoResults extends StatelessWidget {
  const _NoResults();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.space4),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const Icon(Icons.search_off, size: 40, color: AppColors.textSecondary),
            const SizedBox(height: AppSpacing.space3),
            Text(
              'No services match your search',
              textAlign: TextAlign.center,
              style: AppTypography.body.copyWith(color: AppColors.textSecondary),
            ),
          ],
        ),
      ),
    );
  }
}
