import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../providers/booking_providers.dart';

/// Additional Notes — optional multiline free text.
class BookingNotesSection extends ConsumerStatefulWidget {
  const BookingNotesSection({super.key});

  @override
  ConsumerState<BookingNotesSection> createState() => _BookingNotesSectionState();
}

class _BookingNotesSectionState extends ConsumerState<BookingNotesSection> {
  static const int _maxLength = 500;

  late final TextEditingController _controller;
  late int _characterCount;

  @override
  void initState() {
    super.initState();
    final String initialText = ref.read(bookingDraftProvider).notes;
    _controller = TextEditingController(text: initialText);
    _characterCount = initialText.length;
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Additional Notes'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: <Widget>[
              TextField(
                controller: _controller,
                maxLines: 4,
                minLines: 4,
                maxLength: _maxLength,
                style: AppTypography.body,
                onChanged: (String value) {
                  ref.read(bookingDraftProvider.notifier).updateNotes(value);
                  setState(() => _characterCount = value.length);
                },
                decoration: InputDecoration(
                  hintText: 'Anything the team should know before arriving? (optional)',
                  hintStyle: AppTypography.body.copyWith(color: AppColors.textSecondary),
                  filled: true,
                  fillColor: AppColors.white,
                  contentPadding: const EdgeInsets.all(AppSpacing.space3),
                  // The counter is shown separately below (with an
                  // "Optional" cue), so suppress the built-in one to avoid
                  // showing it twice.
                  counterText: '',
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
              const SizedBox(height: AppSpacing.space1),
              Text(
                'Optional · $_characterCount/$_maxLength',
                style: AppTypography.caption,
              ),
            ],
          ),
        ),
      ],
    );
  }
}
