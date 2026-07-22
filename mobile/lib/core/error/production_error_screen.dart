import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';

/// Generic fallback shown instead of Flutter's red error screen outside
/// debug builds — fulfills docs/09_Flutter_Architecture.md §11.2 "Never
/// show red error screens in production", wired up as part of Milestone F3
/// Global Error Handling. Localized, actionable copy per feature screens is
/// a later concern; this is the infrastructure-level fallback only.
class ProductionErrorScreen extends StatelessWidget {
  const ProductionErrorScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.background,
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.space4),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              const Icon(Icons.error_outline, color: AppColors.error, size: 40),
              const SizedBox(height: AppSpacing.space3),
              Text(
                'Something went wrong.',
                style: Theme.of(context).textTheme.headlineSmall,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: AppSpacing.space2),
              Text(
                'Please try again.',
                style: Theme.of(context).textTheme.bodyLarge,
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
