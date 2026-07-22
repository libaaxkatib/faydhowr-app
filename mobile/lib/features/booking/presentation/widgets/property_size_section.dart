import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_spacing.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../data/booking_mock_data.dart';
import '../../domain/entities/booking_entities.dart';
import '../providers/booking_providers.dart';
import 'booking_selectable_card.dart';

/// Property Size — mock size options (Studio … 4+ Bedroom). Exactly one
/// selectable. A `Wrap`, not a fixed grid, so it naturally fits more
/// options per row on wider (tablet) screens.
class PropertySizeSection extends ConsumerWidget {
  const PropertySizeSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final String? selected = ref.watch(bookingDraftProvider).propertySizeId;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Property Size'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: Wrap(
            // Explicit 12px gutter (not the 8pt token scale) per the
            // approved polish spec, so every chip reads evenly spaced.
            spacing: 12,
            runSpacing: 12,
            children: <Widget>[
              for (final PropertySizeOption size in mockPropertySizes)
                // `IntrinsicWidth` makes the chip report its own natural
                // (shrink-to-fit) width to the `Wrap` — otherwise the
                // shared card's `alignment: Alignment.center` (needed so
                // Property Type/Preferred Time content centers inside
                // their *tight*-constrained grid/Expanded cells) instead
                // expands the chip to the Wrap's full bounded row width.
                IntrinsicWidth(
                  child: BookingSelectableCard(
                    label: size.label,
                    selected: selected == size.id,
                    horizontalPadding: AppSpacing.space3,
                    onTap: () => ref.read(bookingDraftProvider.notifier).selectPropertySize(size.id),
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
