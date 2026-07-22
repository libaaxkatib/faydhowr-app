import 'package:flutter/material.dart';

/// Approved brand colors — docs/01_Brand_Design_Guide.md §3.1.
/// Mandatory, do not substitute or invent additional colors.
abstract final class AppColors {
  static const Color primary = Color(0xFF0E339D);
  static const Color secondary = Color(0xFF0694AC);
  static const Color white = Color(0xFFFFFFFF);
  static const Color background = Color(0xFFF8FAFC);
  static const Color textPrimary = Color(0xFF1F2937);
  static const Color textSecondary = Color(0xFF6B7280);
  static const Color border = Color(0xFFE5E7EB);
  static const Color success = Color(0xFF22C55E);
  static const Color warning = Color(0xFFF59E0B);
  static const Color error = Color(0xFFEF4444);
}
