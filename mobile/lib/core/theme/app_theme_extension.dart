import 'package:flutter/material.dart';

import 'app_colors.dart';

/// Carries brand-semantic colors that Material's [ColorScheme] has no slot
/// for (success/warning/border) — docs/09_Flutter_Architecture.md §12.
@immutable
class AppThemeExtension extends ThemeExtension<AppThemeExtension> {
  const AppThemeExtension({
    required this.success,
    required this.warning,
    required this.border,
  });

  final Color success;
  final Color warning;
  final Color border;

  static const AppThemeExtension light = AppThemeExtension(
    success: AppColors.success,
    warning: AppColors.warning,
    border: AppColors.border,
  );

  @override
  AppThemeExtension copyWith({Color? success, Color? warning, Color? border}) {
    return AppThemeExtension(
      success: success ?? this.success,
      warning: warning ?? this.warning,
      border: border ?? this.border,
    );
  }

  @override
  AppThemeExtension lerp(ThemeExtension<AppThemeExtension>? other, double t) {
    if (other is! AppThemeExtension) {
      return this;
    }
    return AppThemeExtension(
      success: Color.lerp(success, other.success, t) ?? success,
      warning: Color.lerp(warning, other.warning, t) ?? warning,
      border: Color.lerp(border, other.border, t) ?? border,
    );
  }
}
