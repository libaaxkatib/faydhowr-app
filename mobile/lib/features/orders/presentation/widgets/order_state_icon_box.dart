import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';

/// Boxed icon for Orders' "state" screens (empty history, load error) —
/// same visual family as the Store Module's `StateIconBox` (own copy:
/// features must not import another feature's presentation,
/// docs/09_Flutter_Architecture.md §3.1).
class OrderStateIconBox extends StatelessWidget {
  const OrderStateIconBox({required this.icon, this.color = AppColors.textSecondary, super.key});

  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 72,
      height: 72,
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: color.withValues(alpha: 0.24)),
      ),
      alignment: Alignment.center,
      child: Icon(icon, size: 32, color: color),
    );
  }
}
