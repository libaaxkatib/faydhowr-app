import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';

/// Visual treatment for a selected [BookingSelectableCard].
///
/// [outlineTinted] — light primary-tinted background, primary border and
/// text (Property Type, Property Size, Preferred Time). [solidPrimary] —
/// solid Primary Blue background with white text, used only where the
/// selection should read as the dominant choice (Booking Type).
enum BookingCardVariant { outlineTinted, solidPrimary }

/// Single-select tappable card shared by every radio-style choice in the
/// Booking form (Booking Type, Property Type, Property Size, Preferred
/// Time) — one visual language, reused instead of four near-duplicate
/// implementations. All state transitions (background, border, text
/// weight, check badge) animate over 200ms, comfortably under the 250ms
/// polish budget.
class BookingSelectableCard extends StatelessWidget {
  const BookingSelectableCard({
    required this.label,
    required this.selected,
    required this.onTap,
    this.icon,
    this.iconSize = 20,
    this.stacked = false,
    this.variant = BookingCardVariant.outlineTinted,
    this.horizontalPadding = AppSpacing.space2,
    super.key,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;
  final IconData? icon;
  final double iconSize;
  final double horizontalPadding;

  /// When true, lays out icon-above-label (centered) instead of
  /// side-by-side — used where 3+ equal-width cards in a `Row` would
  /// otherwise leave a longer label (e.g. "Afternoon") too little width
  /// next to an icon and force an ugly mid-word wrap.
  final bool stacked;

  final BookingCardVariant variant;

  static const Duration _animationDuration = Duration(milliseconds: 200);

  bool get _isSolid => selected && variant == BookingCardVariant.solidPrimary;

  Color get _background {
    if (!selected) {
      return AppColors.white;
    }
    return _isSolid ? AppColors.primary : AppColors.primary.withValues(alpha: 0.08);
  }

  Color get _borderColor => selected ? AppColors.primary : AppColors.border;

  Color get _foreground {
    if (!selected) {
      return AppColors.textPrimary;
    }
    return _isSolid ? AppColors.white : AppColors.primary;
  }

  Color get _iconColor {
    if (!selected) {
      return AppColors.textSecondary;
    }
    return _isSolid ? AppColors.white : AppColors.primary;
  }

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      label: label,
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(AppRadius.md),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.md),
          onTap: onTap,
          // The badge is a sibling of the card body, not a child of it —
          // so it anchors to the card's actual rendered corner (full
          // Expanded/grid-cell width) rather than to the inline content's
          // own (possibly narrower) intrinsic size.
          child: Stack(
            clipBehavior: Clip.none,
            children: <Widget>[
              AnimatedContainer(
                duration: _animationDuration,
                curve: Curves.easeOut,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: _background,
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  border: Border.all(color: _borderColor, width: selected ? 1.5 : 1),
                ),
                padding: EdgeInsets.symmetric(horizontal: horizontalPadding, vertical: AppSpacing.space3),
                child: stacked ? _buildStackedContent() : _buildInlineContent(),
              ),
              Positioned(
                top: _isSolid ? AppSpacing.space2 : -6,
                right: _isSolid ? AppSpacing.space2 : -6,
                child: _buildCheckBadge(),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildCheckBadge() {
    // `solidPrimary`: a bare white check reads fine directly against the
    // solid blue fill, inset just inside the card's own corner.
    // `outlineTinted`: the card fill is a faint tint, so the check sits on
    // a small white backdrop circle, floating just outside the corner —
    // the same "premium badge" treatment used elsewhere in this module.
    final Widget icon = Icon(Icons.check_circle, color: _isSolid ? AppColors.white : AppColors.primary, size: 18);
    return AnimatedScale(
      duration: _animationDuration,
      curve: Curves.easeOut,
      scale: selected ? 1 : 0,
      child: AnimatedOpacity(
        duration: _animationDuration,
        opacity: selected ? 1 : 0,
        child: _isSolid
            ? icon
            : DecoratedBox(decoration: const BoxDecoration(color: AppColors.white, shape: BoxShape.circle), child: icon),
      ),
    );
  }

  Widget _animatedLabel() {
    return AnimatedDefaultTextStyle(
      duration: _animationDuration,
      curve: Curves.easeOut,
      style: AppTypography.bodySmall.copyWith(
        color: _foreground,
        fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
      ),
      child: Text(label, textAlign: TextAlign.center, maxLines: 1, overflow: TextOverflow.ellipsis),
    );
  }

  Widget _buildInlineContent() {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        if (icon != null) ...<Widget>[
          Icon(icon, color: _iconColor, size: iconSize),
          const SizedBox(width: AppSpacing.space2),
        ],
        Flexible(child: _animatedLabel()),
      ],
    );
  }

  Widget _buildStackedContent() {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        if (icon != null) ...<Widget>[
          Icon(icon, color: _iconColor, size: iconSize),
          const SizedBox(height: AppSpacing.space2),
        ],
        _animatedLabel(),
      ],
    );
  }
}
