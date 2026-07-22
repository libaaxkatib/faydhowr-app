import 'package:flutter/material.dart';

import '../../core/theme/app_spacing.dart';
import '../../core/theme/app_typography.dart';

/// Generic section title, reusable across features. Visually identical to
/// Home's own `HomeSectionHeader` — Home keeps its private copy unchanged
/// (the Home Module is frozen); this is the first genuinely shared widget,
/// used by the Services Module.
class SectionHeader extends StatelessWidget {
  const SectionHeader({required this.title, super.key});

  final String title;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.space3,
        AppSpacing.space5,
        AppSpacing.space3,
        AppSpacing.space3,
      ),
      child: Semantics(
        header: true,
        child: Text(title, style: AppTypography.heading3),
      ),
    );
  }
}
