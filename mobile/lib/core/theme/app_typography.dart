import 'package:flutter/material.dart';

import 'app_colors.dart';

/// Type scale — docs/08_Figma_Design_System.md §2.
///
/// Font family is Plus Jakarta Sans per the approved Brand/Figma spec. The
/// actual font asset files are not yet bundled in this milestone (Foundation
/// scope is theme tokens, not asset sourcing); `fontFamilyFallback` renders a
/// reasonable system font until the real asset is added, matching the
/// documented fallback behavior in docs/01_Brand_Design_Guide.md §4.1.
abstract final class AppTypography {
  static const String fontFamily = 'PlusJakartaSans';

  static const List<String> _fallback = <String>[
    'Roboto',
    'Helvetica Neue',
    'Arial',
    'sans-serif',
  ];

  static const TextStyle heading1 = TextStyle(
    fontFamily: fontFamily,
    fontFamilyFallback: _fallback,
    fontSize: 32,
    height: 40 / 32,
    fontWeight: FontWeight.w600,
    color: AppColors.textPrimary,
  );

  static const TextStyle heading2 = TextStyle(
    fontFamily: fontFamily,
    fontFamilyFallback: _fallback,
    fontSize: 24,
    height: 32 / 24,
    fontWeight: FontWeight.w600,
    color: AppColors.textPrimary,
  );

  static const TextStyle heading3 = TextStyle(
    fontFamily: fontFamily,
    fontFamilyFallback: _fallback,
    fontSize: 18,
    height: 28 / 18,
    fontWeight: FontWeight.w600,
    color: AppColors.textPrimary,
  );

  static const TextStyle body = TextStyle(
    fontFamily: fontFamily,
    fontFamilyFallback: _fallback,
    fontSize: 16,
    height: 24 / 16,
    fontWeight: FontWeight.w400,
    color: AppColors.textPrimary,
  );

  static const TextStyle caption = TextStyle(
    fontFamily: fontFamily,
    fontFamilyFallback: _fallback,
    fontSize: 12,
    height: 16 / 12,
    fontWeight: FontWeight.w400,
    color: AppColors.textSecondary,
  );

  static const TextStyle button = TextStyle(
    fontFamily: fontFamily,
    fontFamilyFallback: _fallback,
    fontSize: 16,
    height: 24 / 16,
    fontWeight: FontWeight.w600,
  );

  /// Supporting style — docs/08_Figma_Design_System.md §2 "Supporting text styles".
  static const TextStyle bodySmall = TextStyle(
    fontFamily: fontFamily,
    fontFamilyFallback: _fallback,
    fontSize: 14,
    fontWeight: FontWeight.w400,
    color: AppColors.textPrimary,
  );

  /// Supporting style.
  static const TextStyle subtitle = TextStyle(
    fontFamily: fontFamily,
    fontFamilyFallback: _fallback,
    fontSize: 16,
    fontWeight: FontWeight.w500,
    color: AppColors.textSecondary,
  );

  /// Supporting style — used for price display.
  static const TextStyle price = TextStyle(
    fontFamily: fontFamily,
    fontFamilyFallback: _fallback,
    fontSize: 16,
    fontWeight: FontWeight.w600,
    color: AppColors.textPrimary,
  );
}
