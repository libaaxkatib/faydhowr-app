import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/booking_entities.dart';
import '../providers/booking_providers.dart';

/// Two separate upload cards — Photos / Video. Icon-only placeholders, no
/// real file picker: tapping just toggles a mock "added" indicator.
class BookingUploadSection extends ConsumerWidget {
  const BookingUploadSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final BookingDraft draft = ref.watch(bookingDraftProvider);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Photos & Video'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: Row(
            children: <Widget>[
              Expanded(
                child: _UploadCard(
                  icon: Icons.add_a_photo_outlined,
                  label: 'Upload Photos',
                  helperText: 'JPG, PNG\nMax 10 files',
                  // Mock count — there's no real file picker in Phase 1, so
                  // this is a representative placeholder, not a live tally.
                  addedLabel: '3 Photos Added',
                  added: draft.photosAdded,
                  onTap: () => ref.read(bookingDraftProvider.notifier).togglePhotos(),
                ),
              ),
              const SizedBox(width: AppSpacing.space3),
              Expanded(
                child: _UploadCard(
                  icon: Icons.videocam_outlined,
                  label: 'Upload Video',
                  helperText: 'MP4\nMax 100 MB',
                  addedLabel: '1 Video Added',
                  added: draft.videoAdded,
                  onTap: () => ref.read(bookingDraftProvider.notifier).toggleVideo(),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _UploadCard extends StatelessWidget {
  const _UploadCard({
    required this.icon,
    required this.label,
    required this.helperText,
    required this.addedLabel,
    required this.added,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final String helperText;
  final String addedLabel;
  final bool added;
  final VoidCallback onTap;

  static const Duration _animationDuration = Duration(milliseconds: 200);

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: added ? '$label, $addedLabel' : label,
      child: Material(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(AppRadius.md),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.md),
          onTap: onTap,
          child: AnimatedContainer(
            duration: _animationDuration,
            curve: Curves.easeOut,
            decoration: BoxDecoration(
              color: added ? AppColors.success.withValues(alpha: 0.06) : AppColors.white,
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: added ? AppColors.success : AppColors.border, width: added ? 1.5 : 1),
            ),
            padding: const EdgeInsets.symmetric(vertical: AppSpacing.space4),
            child: Column(
              children: <Widget>[
                AnimatedSwitcher(
                  duration: _animationDuration,
                  transitionBuilder: (Widget child, Animation<double> animation) =>
                      ScaleTransition(scale: animation, child: child),
                  child: Icon(
                    added ? Icons.check_circle_outline : icon,
                    key: ValueKey<bool>(added),
                    color: added ? AppColors.success : AppColors.primary,
                    size: 28,
                  ),
                ),
                const SizedBox(height: AppSpacing.space2),
                AnimatedDefaultTextStyle(
                  duration: _animationDuration,
                  curve: Curves.easeOut,
                  style: AppTypography.bodySmall.copyWith(
                    color: added ? AppColors.success : AppColors.textSecondary,
                    fontWeight: FontWeight.w600,
                  ),
                  child: Text(added ? addedLabel : label, textAlign: TextAlign.center),
                ),
                if (!added) ...<Widget>[
                  const SizedBox(height: AppSpacing.space1),
                  Text(
                    helperText,
                    textAlign: TextAlign.center,
                    style: AppTypography.caption.copyWith(color: AppColors.textSecondary),
                  ),
                ],
                if (added) ...<Widget>[
                  const SizedBox(height: AppSpacing.space1),
                  // Secondary action — UI only, reuses the same toggle so
                  // it doesn't add a second mock-data path for Phase 1.
                  InkWell(
                    borderRadius: BorderRadius.circular(AppRadius.sm),
                    onTap: onTap,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space2, vertical: 2),
                      child: Text(
                        'Manage',
                        style: AppTypography.caption.copyWith(
                          color: AppColors.secondary,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
