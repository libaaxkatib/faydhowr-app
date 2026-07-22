import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../data/booking_mock_data.dart';
import '../providers/booking_providers.dart';

/// Preferred Date — a single tappable card that opens a mock picker (a
/// static list of date labels in a bottom sheet). No real calendar widget,
/// per Phase 1 scope.
class PreferredDateSection extends ConsumerWidget {
  const PreferredDateSection({super.key});

  Future<void> _openMockDatePicker(BuildContext context, WidgetRef ref) async {
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
                child: Text('Select Date', style: AppTypography.heading3),
              ),
              for (final String date in mockDateOptions)
                ListTile(
                  title: Text(date, style: AppTypography.body),
                  onTap: () => Navigator.of(context).pop(date),
                ),
              const SizedBox(height: AppSpacing.space2),
            ],
          ),
        );
      },
    );
    if (picked != null) {
      ref.read(bookingDraftProvider.notifier).selectDate(picked);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final String? selectedDate = ref.watch(bookingDraftProvider).preferredDateLabel;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Preferred Date'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: Semantics(
            button: true,
            label: selectedDate == null ? 'Select Date' : 'Preferred date: $selectedDate',
            child: Container(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(AppRadius.lg),
                boxShadow: <BoxShadow>[
                  BoxShadow(
                    color: AppColors.primary.withValues(alpha: 0.08),
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
                  onTap: () => _openMockDatePicker(context, ref),
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.space3),
                    child: Row(
                      children: <Widget>[
                        Container(
                          width: 40,
                          height: 40,
                          decoration: BoxDecoration(
                            color: AppColors.primary.withValues(alpha: 0.08),
                            borderRadius: BorderRadius.circular(AppRadius.sm),
                          ),
                          alignment: Alignment.center,
                          child: const Icon(Icons.calendar_today_outlined, color: AppColors.primary, size: 20),
                        ),
                        const SizedBox(width: AppSpacing.space3),
                        Expanded(
                          child: Text(
                            selectedDate ?? 'Select Date',
                            style: AppTypography.body.copyWith(
                              color: selectedDate == null ? AppColors.textSecondary : AppColors.textPrimary,
                              fontWeight: selectedDate == null ? FontWeight.w400 : FontWeight.w700,
                            ),
                          ),
                        ),
                        const Icon(Icons.chevron_right, color: AppColors.textSecondary),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}
