import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_spacing.dart';

/// State a sticky action button can be presented in. The Booking Module
/// will drive this in the future; for now every caller defaults to
/// [disabled] so behavior is unchanged.
enum ServiceActionState { disabled, enabled, loading }

/// Bottom sticky action bar — Book Now / Request Quotation. Both remain
/// disabled by default until the Booking Module exists; this widget only
/// exposes the state surface (disabled/enabled/loading) a future module
/// can drive — no booking or quotation logic lives here.
class ServiceStickyActions extends StatelessWidget {
  const ServiceStickyActions({
    this.bookNowState = ServiceActionState.disabled,
    this.requestQuotationState = ServiceActionState.disabled,
    this.onBookNow,
    this.onRequestQuotation,
    super.key,
  });

  final ServiceActionState bookNowState;
  final ServiceActionState requestQuotationState;
  final VoidCallback? onBookNow;
  final VoidCallback? onRequestQuotation;

  @override
  Widget build(BuildContext context) {
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
          padding: const EdgeInsets.all(AppSpacing.space3),
          child: Row(
            children: <Widget>[
              Expanded(
                child: _StickyActionButton(
                  label: 'Book Now',
                  state: bookNowState,
                  filled: true,
                  onPressed: onBookNow,
                ),
              ),
              const SizedBox(width: AppSpacing.space3),
              Expanded(
                child: _StickyActionButton(
                  label: 'Request Quotation',
                  state: requestQuotationState,
                  filled: false,
                  onPressed: onRequestQuotation,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _StickyActionButton extends StatelessWidget {
  const _StickyActionButton({
    required this.label,
    required this.state,
    required this.filled,
    required this.onPressed,
  });

  final String label;
  final ServiceActionState state;
  final bool filled;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final bool isLoading = state == ServiceActionState.loading;
    final bool isEnabled = state == ServiceActionState.enabled;
    final String semanticsSuffix = switch (state) {
      ServiceActionState.disabled => 'currently unavailable',
      ServiceActionState.enabled => 'available',
      ServiceActionState.loading => 'loading',
    };

    final Widget child = isLoading
        ? SizedBox(
            width: 20,
            height: 20,
            child: CircularProgressIndicator(
              strokeWidth: 2.4,
              color: filled ? AppColors.white : AppColors.primary,
            ),
          )
        : Text(label);

    final VoidCallback? effectiveOnPressed = (isEnabled && !isLoading) ? onPressed : null;

    return Semantics(
      button: true,
      label: '$label, $semanticsSuffix',
      child: filled
          ? ElevatedButton(onPressed: effectiveOnPressed, child: child)
          : OutlinedButton(onPressed: effectiveOnPressed, child: child),
    );
  }
}
