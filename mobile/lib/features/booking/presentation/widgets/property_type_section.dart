import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/booking_entities.dart';
import '../providers/booking_providers.dart';
import 'booking_selectable_card.dart';

/// Property Type — responsive grid (2 columns on phone, 3 on tablet+) of
/// the approved property types, plus "Other" with a free-text field.
/// Exactly one type selectable.
class PropertyTypeSection extends ConsumerStatefulWidget {
  const PropertyTypeSection({super.key});

  @override
  ConsumerState<PropertyTypeSection> createState() => _PropertyTypeSectionState();
}

class _PropertyTypeSectionState extends ConsumerState<PropertyTypeSection> {
  late final TextEditingController _customTypeController;

  static int _crossAxisCountFor(double width) => width < 600 ? 2 : 3;

  // Slightly shorter than the first pass, with a bigger icon — reads as a
  // tighter, more premium tile without losing tap-target comfort.
  static const double _cellHeight = 50;
  static const double _iconSize = 28;

  @override
  void initState() {
    super.initState();
    _customTypeController = TextEditingController(text: ref.read(bookingDraftProvider).customPropertyType);
  }

  @override
  void dispose() {
    _customTypeController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final PropertyType? selected = ref.watch(bookingDraftProvider).propertyType;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Property Type'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: LayoutBuilder(
            builder: (BuildContext context, BoxConstraints constraints) {
              final int crossAxisCount = _crossAxisCountFor(constraints.maxWidth);
              final double cellWidth =
                  (constraints.maxWidth - (crossAxisCount - 1) * AppSpacing.space3) / crossAxisCount;
              return GridView.builder(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: PropertyType.values.length,
                gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: crossAxisCount,
                  crossAxisSpacing: AppSpacing.space3,
                  mainAxisSpacing: AppSpacing.space3,
                  childAspectRatio: cellWidth / _cellHeight,
                ),
                itemBuilder: (BuildContext context, int index) {
                  final PropertyType type = PropertyType.values[index];
                  return BookingSelectableCard(
                    label: type.label,
                    icon: type.icon,
                    iconSize: _iconSize,
                    selected: selected == type,
                    onTap: () => ref.read(bookingDraftProvider.notifier).selectPropertyType(type),
                  );
                },
              );
            },
          ),
        ),
        if (selected == PropertyType.other) ...<Widget>[
          const SizedBox(height: AppSpacing.space3),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
            child: TextField(
              controller: _customTypeController,
              style: AppTypography.body,
              onChanged: (String value) => ref.read(bookingDraftProvider.notifier).updateCustomPropertyType(value),
              decoration: InputDecoration(
                hintText: 'Enter property type',
                hintStyle: AppTypography.body.copyWith(color: AppColors.textSecondary),
                filled: true,
                fillColor: AppColors.white,
                contentPadding: const EdgeInsets.all(AppSpacing.space3),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  borderSide: const BorderSide(color: AppColors.border),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  borderSide: const BorderSide(color: AppColors.border),
                ),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  borderSide: const BorderSide(color: AppColors.primary),
                ),
              ),
            ),
          ),
        ],
      ],
    );
  }
}
