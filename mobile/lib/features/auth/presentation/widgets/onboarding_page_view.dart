import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import 'onboarding_hero.dart';
import 'onboarding_page_data.dart';

/// Single onboarding page — premium redesign: a large hero illustration
/// filling the upper half of the page, a bold headline, and a short
/// supporting description underneath.
class OnboardingPageView extends StatelessWidget {
  const OnboardingPageView({required this.data, super.key});

  final OnboardingPageData data;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: <Widget>[
        Expanded(flex: 10, child: OnboardingHero(data: data)),
        Expanded(
          flex: 7,
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space5),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: <Widget>[
                Text(data.title, style: AppTypography.heading1, textAlign: TextAlign.center),
                const SizedBox(height: AppSpacing.space3),
                Text(
                  data.description,
                  style: AppTypography.body.copyWith(color: AppColors.textSecondary),
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
