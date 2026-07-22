import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';

/// Boxed icon used across the Store Module's "state" screens (empty
/// catalog, empty cart, load error) so all three read as one consistent
/// visual family rather than three ad-hoc treatments of a bare [Icon].
class StateIconBox extends StatelessWidget {
  const StateIconBox({required this.icon, this.color = AppColors.textSecondary, super.key});

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
