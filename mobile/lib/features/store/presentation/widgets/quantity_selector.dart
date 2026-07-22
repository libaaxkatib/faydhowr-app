import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_typography.dart';

/// Standard `− Qty +` control (docs/05_UI_UX_Design.md §10.2) — used on
/// both Product Details (choosing a quantity to add) and Cart (updating an
/// existing line). Never a free-typed field. Minimum is always 1 here —
/// dropping to 0 is Cart's explicit Remove action, not this stepper.
class QuantitySelector extends StatelessWidget {
  const QuantitySelector({
    required this.quantity,
    required this.onChanged,
    this.maxQuantity,
    super.key,
  });

  final int quantity;
  final ValueChanged<int> onChanged;

  /// Stock ceiling — `null` means no cap is known/needed.
  final int? maxQuantity;

  @override
  Widget build(BuildContext context) {
    final bool canDecrement = quantity > 1;
    final bool canIncrement = maxQuantity == null || quantity < maxQuantity!;

    return DecoratedBox(
      decoration: BoxDecoration(
        border: Border.all(color: AppColors.border),
        borderRadius: const BorderRadius.all(Radius.circular(999)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          _StepButton(
            icon: Icons.remove,
            enabled: canDecrement,
            semanticsLabel: 'Decrease quantity',
            onTap: () => onChanged(quantity - 1),
          ),
          SizedBox(
            width: 32,
            child: Text('$quantity', textAlign: TextAlign.center, style: AppTypography.bodySmall),
          ),
          _StepButton(
            icon: Icons.add,
            enabled: canIncrement,
            semanticsLabel: 'Increase quantity',
            onTap: () => onChanged(quantity + 1),
          ),
        ],
      ),
    );
  }
}

class _StepButton extends StatelessWidget {
  const _StepButton({
    required this.icon,
    required this.enabled,
    required this.semanticsLabel,
    required this.onTap,
  });

  final IconData icon;
  final bool enabled;
  final String semanticsLabel;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final Color color = enabled ? AppColors.primary : AppColors.textSecondary.withValues(alpha: 0.4);
    return Semantics(
      button: true,
      label: semanticsLabel,
      child: InkWell(
        onTap: enabled ? onTap : null,
        customBorder: const CircleBorder(),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Icon(icon, size: 16, color: color),
        ),
      ),
    );
  }
}
