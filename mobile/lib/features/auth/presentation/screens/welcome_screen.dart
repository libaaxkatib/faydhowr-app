import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../providers/onboarding_providers.dart';

/// Welcome Screen (Authentication UX Revision) — a branding screen only,
/// no business actions. Shown on every launch, first-install and
/// returning-user alike; its Continue/swipe action (or, for a returning
/// user, a 4-second auto-advance timer) is the only place that branches
/// to Onboarding (first time) vs. Home (already onboarded), reading the
/// same `hasCompletedOnboardingProvider` Onboarding already used — no
/// persistence or Auth logic changed.
///
/// Layout matches the approved reference mockup as closely as possible:
/// an edge-to-edge rounded hero card, a brand lockup + notification-bell
/// overlay across the top of the hero, left-aligned headline/description,
/// and a single white "Continue" button in the dark panel below (the
/// reference's "Book a Service" / "Browse Store" pair is replaced with
/// this one button — branding only, no business actions).
///
/// Brand identity (docs/01_Brand_Design_Guide.md §3.1 — mandatory, both
/// colors): the hero photo carries a Dark Blue (`AppColors.primary`,
/// #0E339D) → Turquoise (`AppColors.secondary`, #0694AC) scrim so the
/// gradient still reads clearly over the photograph; the headline
/// underline and info panel reuse the same two colors so neither ever
/// appears alone.
///
/// The hero image is a real photograph — `assets/images/welcome_hero.jpg`,
/// sourced from Fayadhowr's own site (fayadhowrcleaning.com, "Hiring of
/// Cleaners and Housekeepers"), not a stock photo or vector placeholder.
class WelcomeScreen extends ConsumerStatefulWidget {
  const WelcomeScreen({super.key});

  @override
  ConsumerState<WelcomeScreen> createState() => _WelcomeScreenState();
}

class _WelcomeScreenState extends ConsumerState<WelcomeScreen> {
  static const LinearGradient _brandGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: <Color>[AppColors.primary, AppColors.secondary],
  );

  static const Duration _returningUserAutoAdvance = Duration(seconds: 4);

  Timer? _autoAdvanceTimer;

  @override
  void initState() {
    super.initState();
    // Returning user: auto-continue to Home after 3-5s even with no
    // interaction. First-time users always wait for Continue/swipe.
    if (ref.read(hasCompletedOnboardingProvider)) {
      _autoAdvanceTimer = Timer(_returningUserAutoAdvance, _continue);
    }
  }

  @override
  void dispose() {
    _autoAdvanceTimer?.cancel();
    super.dispose();
  }

  void _continue() {
    _autoAdvanceTimer?.cancel();
    if (!mounted) {
      return;
    }
    final bool hasOnboarded = ref.read(hasCompletedOnboardingProvider);
    context.go(hasOnboarded ? '/home' : '/onboarding');
  }

  void _onHorizontalDragEnd(DragEndDetails details) {
    final double velocity = details.primaryVelocity ?? 0;
    if (velocity < 0) {
      // Leftward swipe — "Swipe →" per the approved launch flow.
      _continue();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      body: GestureDetector(
        onHorizontalDragEnd: _onHorizontalDragEnd,
        behavior: HitTestBehavior.translucent,
        // The swipe-to-continue gesture has no meaningful screen-reader
        // action of its own (the Continue button already exposes the same
        // action) — without this, Flutter merges every descendant's
        // semantics into one giant "scrollable" node, so headline,
        // description, and the button all stop being individually
        // focusable for TalkBack/VoiceOver users.
        excludeFromSemantics: true,
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.space2),
            child: DecoratedBox(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(AppRadius.xl),
                boxShadow: <BoxShadow>[
                  BoxShadow(
                    color: AppColors.primary.withValues(alpha: 0.18),
                    blurRadius: 24,
                    offset: const Offset(0, 10),
                  ),
                ],
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(AppRadius.xl),
                child: Column(
                  children: <Widget>[
                    const Expanded(flex: 11, child: _HeroIllustration()),
                    Expanded(flex: 9, child: _InfoPanel(gradient: _brandGradient, onContinue: _continue)),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Full-bleed real photograph, a Dark Blue → Turquoise scrim for text
/// legibility (and to carry the mandatory two-color gradient), and the
/// brand lockup + notification-bell overlay across the top — no vector
/// illustration, icons-as-art, or placeholder graphics.
class _HeroIllustration extends StatelessWidget {
  const _HeroIllustration();

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: 'Photo of a Fayadhowr cleaner at work in a modern home',
      image: true,
      child: SizedBox(
        width: double.infinity,
        child: Stack(
          fit: StackFit.expand,
          children: <Widget>[
            Image.asset('assets/images/welcome_hero.jpg', fit: BoxFit.cover),
            // Two-color scrim: keeps the header/photo legible and is the
            // hero's mandatory Dark Blue → Turquoise gradient.
            DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: <Color>[
                    AppColors.primary.withValues(alpha: 0.55),
                    AppColors.secondary.withValues(alpha: 0.25),
                    AppColors.primary.withValues(alpha: 0.85),
                  ],
                  stops: const <double>[0.0, 0.5, 1.0],
                ),
              ),
            ),
            // Top brand lockup + notification bell — matches the reference
            // mockup's in-hero header row. Decorative only (no actions).
            Positioned(
              top: AppSpacing.space3,
              left: AppSpacing.space3,
              right: AppSpacing.space3,
              child: Row(
                children: <Widget>[
                  Container(
                    width: 32,
                    height: 32,
                    decoration: const BoxDecoration(color: AppColors.white, shape: BoxShape.circle),
                    alignment: Alignment.center,
                    child: const Icon(Icons.cleaning_services, size: 16, color: AppColors.primary),
                  ),
                  const SizedBox(width: AppSpacing.space2),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(
                        'Fayadhowr',
                        style: AppTypography.heading3.copyWith(color: AppColors.white),
                      ),
                      Text(
                        'Professional Cleaning',
                        style: AppTypography.caption.copyWith(color: AppColors.white.withValues(alpha: 0.85)),
                      ),
                    ],
                  ),
                  const Spacer(),
                  Container(
                    width: 32,
                    height: 32,
                    decoration: BoxDecoration(
                      color: AppColors.white.withValues(alpha: 0.18),
                      shape: BoxShape.circle,
                    ),
                    alignment: Alignment.center,
                    child: const Icon(Icons.notifications_outlined, size: 16, color: AppColors.white),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Dark panel below the hero: left-aligned headline/description (matching
/// the reference) and a single white "Continue" button — the reference's
/// "Book a Service" / "Browse Store" pair is intentionally not reproduced
/// here; Welcome takes no business actions.
class _InfoPanel extends StatelessWidget {
  const _InfoPanel({required this.gradient, required this.onContinue});

  final Gradient gradient;
  final VoidCallback onContinue;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: AppColors.primary,
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.space4,
        AppSpacing.space4,
        AppSpacing.space4,
        AppSpacing.space3,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Semantics(
            header: true,
            child: Text(
              'Professional Cleaning You Can Trust',
              style: AppTypography.heading1.copyWith(color: AppColors.white),
            ),
          ),
          const SizedBox(height: AppSpacing.space2),
          Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: AppColors.secondary,
              borderRadius: BorderRadius.circular(AppRadius.sm),
            ),
          ),
          const SizedBox(height: AppSpacing.space3),
          Text(
            'Book trusted cleaning services and shop cleaning essentials — all in one place.',
            style: AppTypography.body.copyWith(color: AppColors.white.withValues(alpha: 0.78)),
          ),
          const Spacer(),
          Semantics(
            button: true,
            label: 'Continue',
            child: SizedBox(
              width: double.infinity,
              height: AppSpacing.controlHeight,
              child: Material(
                color: AppColors.white,
                borderRadius: BorderRadius.circular(AppRadius.md),
                child: InkWell(
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  onTap: onContinue,
                  child: Center(
                    child: Text('Continue', style: AppTypography.button.copyWith(color: AppColors.primary)),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
