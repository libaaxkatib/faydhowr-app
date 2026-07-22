import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_spacing.dart';

/// Visual state of the Booking Screen's sticky Continue button. Phase 1
/// has no submission logic — [loading] exists purely as a prepared visual
/// state for a future module to drive.
enum BookingContinueState { disabled, enabled, loading }

/// Sticky bottom Continue button — mirrors the Services Module's
/// `ServiceStickyActions` state-preparation pattern (disabled / enabled /
/// loading), scoped to Booking since features must not import another
/// feature's presentation layer.
class BookingContinueButton extends StatelessWidget {
  const BookingContinueButton({
    required this.state,
    this.onPressed,
    super.key,
  });

  final BookingContinueState state;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final bool isLoading = state == BookingContinueState.loading;
    final bool isEnabled = state == BookingContinueState.enabled;
    final String semanticsSuffix = switch (state) {
      BookingContinueState.disabled => 'currently unavailable',
      BookingContinueState.enabled => 'available',
      BookingContinueState.loading => 'loading',
    };

    return DecoratedBox(
      decoration: BoxDecoration(
        color: AppColors.white,
        border: const Border(top: BorderSide(color: AppColors.border)),
        boxShadow: <BoxShadow>[
          BoxShadow(
            color: AppColors.primary.withValues(alpha: 0.08),
            blurRadius: 16,
            offset: const Offset(0, -4),
          ),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          // Explicit 20px horizontal / 20px bottom margin per the approved
          // polish spec (not the 8pt token scale) — the button reads as
          // inset from the screen edges rather than flush against them.
          padding: const EdgeInsets.fromLTRB(20, AppSpacing.space3, 20, 20),
          child: SizedBox(
            width: double.infinity,
            height: AppSpacing.controlHeight,
            child: Semantics(
              button: true,
              label: 'Continue, $semanticsSuffix',
              child: ElevatedButton(
                onPressed: isEnabled && !isLoading ? onPressed : null,
                style: ElevatedButton.styleFrom(
                  elevation: isEnabled || isLoading ? 3 : 0,
                  shadowColor: AppColors.primary.withValues(alpha: 0.4),
                  animationDuration: const Duration(milliseconds: 200),
                ).copyWith(
                  overlayColor: WidgetStateProperty.all(AppColors.white.withValues(alpha: 0.12)),
                ),
                child: AnimatedSwitcher(
                  duration: const Duration(milliseconds: 200),
                  transitionBuilder: (Widget child, Animation<double> animation) =>
                      FadeTransition(opacity: animation, child: ScaleTransition(scale: animation, child: child)),
                  child: isLoading
                      ? const SizedBox(
                          key: ValueKey<String>('loading'),
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(strokeWidth: 2.4, color: AppColors.white),
                        )
                      : const Text('Continue', key: ValueKey<String>('label')),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
