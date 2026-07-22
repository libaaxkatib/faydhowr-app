import 'package:flutter/material.dart';

import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';

/// Shared section title — reused by every Home section to avoid
/// duplicating title styling nine times.
class HomeSectionHeader extends StatelessWidget {
  const HomeSectionHeader({required this.title, super.key});

  final String title;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.space3,
        AppSpacing.space4,
        AppSpacing.space3,
        AppSpacing.space2,
      ),
      child: Semantics(
        header: true,
        child: Text(title, style: AppTypography.heading3),
      ),
    );
  }
}
