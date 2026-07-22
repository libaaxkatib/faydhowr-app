import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../providers/onboarding_providers.dart';
import '../widgets/onboarding_page_data.dart';
import '../widgets/onboarding_page_view.dart';
import '../widgets/page_indicator.dart';

/// Onboarding flow (Milestone F5 task 2): three pages, Skip/Next/Get
/// Started, page indicator. Completion is remembered locally
/// (`hasCompletedOnboardingProvider`) so it is never shown again on this
/// device once finished or skipped.
class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key});

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  final PageController _pageController = PageController();
  int _currentPage = 0;

  bool get _isLastPage => _currentPage == onboardingPages.length - 1;

  @override
  void dispose() {
    _pageController.dispose();
    super.dispose();
  }

  Future<void> _complete() async {
    await markOnboardingCompleted(ref);
    if (mounted) {
      // Guest browsing is allowed — onboarding leads to Home, not Login.
      // Login is only triggered by the redirect guard on a protected
      // action (see app/app_router.dart).
      context.go('/home');
    }
  }

  void _next() {
    if (_isLastPage) {
      _complete();
    } else {
      _pageController.nextPage(duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.white,
      body: SafeArea(
        child: Column(
          children: <Widget>[
            Align(
              alignment: Alignment.topRight,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(0, AppSpacing.space2, AppSpacing.space4, 0),
                child: TextButton(
                  onPressed: _complete,
                  style: TextButton.styleFrom(
                    backgroundColor: AppColors.background,
                    foregroundColor: AppColors.textSecondary,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.full)),
                    padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space4, vertical: AppSpacing.space2),
                  ),
                  child: const Text('Skip', style: AppTypography.bodySmall),
                ),
              ),
            ),
            Expanded(
              child: PageView.builder(
                controller: _pageController,
                itemCount: onboardingPages.length,
                onPageChanged: (int index) => setState(() => _currentPage = index),
                itemBuilder: (BuildContext context, int index) =>
                    OnboardingPageView(data: onboardingPages[index]),
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.space3),
              child: PageIndicator(count: onboardingPages.length, currentIndex: _currentPage),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.space5,
                AppSpacing.space4,
                AppSpacing.space5,
                AppSpacing.space4,
              ),
              child: SizedBox(
                width: double.infinity,
                height: 56,
                child: ElevatedButton(
                  onPressed: _next,
                  style: ElevatedButton.styleFrom(
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.lg)),
                    elevation: 2,
                    shadowColor: AppColors.primary.withValues(alpha: 0.35),
                  ),
                  child: Text(
                    _isLastPage ? 'Get Started' : 'Next',
                    style: AppTypography.button.copyWith(color: AppColors.white),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
