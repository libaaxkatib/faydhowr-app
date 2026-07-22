import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../data/booking_mock_data.dart';
import '../../domain/entities/booking_entities.dart';
import '../providers/booking_providers.dart';

/// Address — a large card showing the current/selected address, with a
/// "Select Location" action that opens a mock static address list. No
/// Google Maps, GPS, or location services, per Phase 1 scope.
class BookingAddressSection extends ConsumerWidget {
  const BookingAddressSection({super.key});

  Future<void> _openMockAddressPicker(BuildContext context, WidgetRef ref) async {
    final String? picked = await showModalBottomSheet<String>(
      context: context,
      backgroundColor: AppColors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.lg)),
      ),
      builder: (BuildContext context) {
        return SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              const Padding(
                padding: EdgeInsets.fromLTRB(
                  AppSpacing.space3,
                  AppSpacing.space3,
                  AppSpacing.space3,
                  AppSpacing.space2,
                ),
                child: Text('Select Location', style: AppTypography.heading3),
              ),
              for (final BookingAddressOption address in mockAddressOptions)
                ListTile(
                  leading: const Icon(Icons.location_on_outlined, color: AppColors.primary),
                  title: Text(address.title, style: AppTypography.body),
                  subtitle: Text(address.subtitle, style: AppTypography.caption),
                  onTap: () => Navigator.of(context).pop('${address.title} — ${address.subtitle}'),
                ),
              const SizedBox(height: AppSpacing.space2),
            ],
          ),
        );
      },
    );
    if (picked != null) {
      ref.read(bookingDraftProvider.notifier).selectAddress(picked);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final String? selectedAddress = ref.watch(bookingDraftProvider).addressLabel;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Address'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
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
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.space4),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Row(
                      children: <Widget>[
                        Container(
                          width: 36,
                          height: 36,
                          decoration: BoxDecoration(
                            color: AppColors.primary.withValues(alpha: 0.08),
                            borderRadius: BorderRadius.circular(AppRadius.sm),
                          ),
                          alignment: Alignment.center,
                          child: const Icon(Icons.location_on_outlined, color: AppColors.primary, size: 18),
                        ),
                        const SizedBox(width: AppSpacing.space3),
                        Text(
                          'Current Address',
                          style: AppTypography.subtitle.copyWith(fontWeight: FontWeight.w600),
                        ),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.space3),
                    Text(
                      selectedAddress ?? 'No address selected yet',
                      style: AppTypography.body.copyWith(
                        color: selectedAddress == null ? AppColors.textSecondary : AppColors.textPrimary,
                        fontWeight: selectedAddress == null ? FontWeight.w400 : FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: AppSpacing.space4),
                    const Divider(height: 1, color: AppColors.border),
                    const SizedBox(height: AppSpacing.space3),
                    Semantics(
                      button: true,
                      label: selectedAddress == null ? 'Select Location' : 'Change Location',
                      child: InkWell(
                        borderRadius: BorderRadius.circular(AppRadius.sm),
                        onTap: () => _openMockAddressPicker(context, ref),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: <Widget>[
                            Text(
                              selectedAddress == null ? 'Select Location' : 'Change Location',
                              style: AppTypography.bodySmall.copyWith(
                                color: AppColors.secondary,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(width: AppSpacing.space1),
                            const Icon(Icons.arrow_forward, color: AppColors.secondary, size: 16),
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
      ],
    );
  }
}
