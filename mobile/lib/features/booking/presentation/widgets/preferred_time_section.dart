import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_spacing.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/booking_entities.dart';
import '../providers/booking_providers.dart';
import 'booking_selectable_card.dart';

/// Preferred Time — Morning / Afternoon / Evening. Exactly one selectable.
class PreferredTimeSection extends ConsumerWidget {
  const PreferredTimeSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final PreferredTimeSlot? selected = ref.watch(bookingDraftProvider).preferredTime;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Preferred Time'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: Row(
            children: <Widget>[
              for (final PreferredTimeSlot slot in PreferredTimeSlot.values) ...<Widget>[
                if (slot != PreferredTimeSlot.values.first) const SizedBox(width: AppSpacing.space3),
                Expanded(
                  child: SizedBox(
                    height: 88,
                    child: BookingSelectableCard(
                      label: slot.label,
                      icon: slot.icon,
                      iconSize: 24,
                      stacked: true,
                      selected: selected == slot,
                      onTap: () => ref.read(bookingDraftProvider.notifier).selectTimeSlot(slot),
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
