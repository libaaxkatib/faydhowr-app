import 'package:flutter/material.dart';

import '../theme/app_spacing.dart';

/// Generic placeholder screen used by Foundation routes (Milestone F2).
///
/// Not a feature screen — every route in this milestone renders this widget
/// with a different [label] purely to prove the router/shell mechanism
/// works. Real feature screens replace these one at a time in later
/// milestones. Lives in `core/router/` rather than `shared/widgets/`
/// because it is routing scaffolding specific to this milestone, not yet a
/// genuine reusable design-system widget — keeping it here avoids `core/`
/// depending on `shared/`, which the frozen architecture's dependency rule
/// forbids (`core`/`shared` sit beneath `presentation`/`domain`/`data`,
/// not the other way around).
class FoundationPlaceholderScreen extends StatelessWidget {
  const FoundationPlaceholderScreen({required this.label, this.action, super.key});

  final String label;

  /// Optional real entry point out of an otherwise-still-placeholder
  /// screen (e.g. Account linking to the Orders Module) — rendered below
  /// the placeholder copy. `null` (the default) keeps every other caller's
  /// behavior byte-for-byte unchanged.
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final TextTheme textTheme = Theme.of(context).textTheme;
    return Scaffold(
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.space4),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(label, style: textTheme.headlineMedium),
              const SizedBox(height: AppSpacing.space2),
              Text(
                'Foundation placeholder — no feature implemented yet',
                style: textTheme.bodyLarge,
                textAlign: TextAlign.center,
              ),
              if (action != null) ...<Widget>[
                const SizedBox(height: AppSpacing.space4),
                action!,
              ],
            ],
          ),
        ),
      ),
    );
  }
}
