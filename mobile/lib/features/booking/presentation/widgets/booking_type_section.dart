import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_spacing.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/booking_entities.dart';
import '../providers/booking_providers.dart';
import 'booking_selectable_card.dart';

/// Booking Type — One-Time vs. Monthly Contract. Exactly one selectable.
class BookingTypeSection extends ConsumerWidget {
  const BookingTypeSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final BookingType? selected = ref.watch(bookingDraftProvider).bookingType;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Booking Type'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: Row(
            children: <Widget>[
              for (final BookingType type in BookingType.values) ...<Widget>[
                if (type != BookingType.values.first) const SizedBox(width: AppSpacing.space3),
                Expanded(
                  child: SizedBox(
                    height: 60,
                    child: BookingSelectableCard(
                      label: type.label,
                      selected: selected == type,
                      variant: BookingCardVariant.solidPrimary,
                      onTap: () => ref.read(bookingDraftProvider.notifier).selectBookingType(type),
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}
