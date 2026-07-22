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

/// Booking Summary — a read-only recap that updates automatically as the
/// user makes selections above. Unset fields show a placeholder dash
/// rather than being omitted, so the summary's shape never jumps around.
class BookingSummarySection extends ConsumerWidget {
  const BookingSummarySection({required this.serviceId, super.key});

  final String serviceId;

  static const String _unset = '—';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final BookingDraft draft = ref.watch(bookingDraftProvider);
    final BookableService? service = ref.watch(bookingServicesByIdProvider)[serviceId];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Booking Summary'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
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
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.space4),
                child: Column(
                  children: <Widget>[
                    _SummaryRow(label: 'Selected Service', value: service?.name ?? _unset),
                    _SummaryRow(label: 'Booking Type', value: draft.bookingType?.label ?? _unset),
                    _SummaryRow(label: 'Property Type', value: draft.propertyType?.label ?? _unset),
                    _SummaryRow(label: 'Property Size', value: _propertySizeLabel(draft.propertySizeId)),
                    _SummaryRow(label: 'Preferred Date', value: draft.preferredDateLabel ?? _unset),
                    _SummaryRow(label: 'Preferred Time', value: draft.preferredTime?.label ?? _unset, isLast: true),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }

  String _propertySizeLabel(String? sizeId) {
    if (sizeId == null) {
      return _unset;
    }
    for (final PropertySizeOption size in mockPropertySizes) {
      if (size.id == sizeId) {
        return size.label;
      }
    }
    return _unset;
  }
}

class _SummaryRow extends StatelessWidget {
  const _SummaryRow({required this.label, required this.value, this.isLast = false});

  final String label;
  final String value;
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: isLast ? 0 : AppSpacing.space4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: <Widget>[
          Expanded(
            child: Text(label, style: AppTypography.bodySmall.copyWith(color: AppColors.textSecondary)),
          ),
          const SizedBox(width: AppSpacing.space4),
          Flexible(
            child: Text(
              value,
              style: AppTypography.body.copyWith(fontWeight: FontWeight.w700),
              textAlign: TextAlign.right,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }
}
