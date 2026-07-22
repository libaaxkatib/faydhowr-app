import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import 'onboarding_page_data.dart';

/// Large hero illustration for an onboarding page — renders
/// [OnboardingPageData.illustrationAsset] (`BoxFit.contain`, never
/// cropped/stretched) inside a premium white, rounded, shadowed card,
/// with a couple of subtle floating highlight chips at the corners.
///
/// Dispatches to `Image.asset` for raster photos (`.jpg`/`.jpeg`/`.png`)
/// or `SvgPicture.asset` for vector placeholders (`.svg`), based on the
/// asset's file extension — so a page can move from a placeholder SVG to
/// real photography (as Page 1 now has) or vice versa with a data-only
/// change, no changes to this widget.
class OnboardingHero extends StatelessWidget {
  const OnboardingHero({required this.data, super.key});

  final OnboardingPageData data;

  @override
  Widget build(BuildContext context) {
    // Only the first two highlights float as corner chips — kept subtle
    // and few so they don't compete with the illustration itself.
    final List<OnboardingHighlight> floating = data.highlights.take(2).toList();

    return Padding(
      padding: const EdgeInsets.fromLTRB(AppSpacing.space4, AppSpacing.space3, AppSpacing.space4, AppSpacing.space4),
      child: Stack(clipBehavior: Clip.none, children: <Widget>[_buildPanel(), ..._buildFloatingChips(floating)]),
    );
  }

  Widget _buildPanel() {
    return Container(
      width: double.infinity,
      // Premium white "frame" for the artwork — not a colored panel — so
      // the illustration reads as artwork sitting on a card, the same way
      // a photo sits on a white mat. Real Fayadhowr illustrations will be
      // designed against this same white background.
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(AppRadius.xl),
        boxShadow: <BoxShadow>[
          BoxShadow(color: AppColors.primary.withValues(alpha: 0.14), blurRadius: 28, offset: const Offset(0, 14)),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(AppRadius.xl),
        child: Padding(
          // Comfortable but tight margin — the artwork should read as the
          // visual focus (~85-90% of the card's width), not float in a
          // sea of white. `BoxFit.contain` still scales it to fit within
          // this space at its own aspect ratio, never cropped or
          // stretched, so the full subject stays visible either way.
          padding: const EdgeInsets.all(AppSpacing.space3),
          child: SizedBox.expand(child: _buildArtwork()),
        ),
      ),
    );
  }

  Widget _buildArtwork() {
    final String asset = data.illustrationAsset;
    if (asset.toLowerCase().endsWith('.svg')) {
      return SvgPicture.asset(asset, fit: BoxFit.contain);
    }
    return Image.asset(asset, fit: BoxFit.contain);
  }

  List<Widget> _buildFloatingChips(List<OnboardingHighlight> highlights) {
    if (highlights.isEmpty) {
      return const <Widget>[];
    }
    final List<Widget> chips = <Widget>[
      Positioned(top: -AppSpacing.space2, left: AppSpacing.space2, child: _chip(highlights.first)),
    ];
    if (highlights.length > 1) {
      chips.add(Positioned(bottom: -AppSpacing.space2, right: AppSpacing.space2, child: _chip(highlights[1])));
    }
    return chips;
  }

  /// Subtle floating chip — smaller and softer than a primary CTA, so it
  /// reads as ambient supporting detail rather than competing with the
  /// illustration underneath it.
  Widget _chip(OnboardingHighlight highlight) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space2, vertical: 6),
      decoration: BoxDecoration(
        color: AppColors.white.withValues(alpha: 0.92),
        borderRadius: BorderRadius.circular(AppRadius.full),
        boxShadow: <BoxShadow>[
          BoxShadow(color: AppColors.primary.withValues(alpha: 0.12), blurRadius: 10, offset: const Offset(0, 4)),
        ],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Icon(Icons.check_circle_rounded, size: 14, color: AppColors.success),
          const SizedBox(width: AppSpacing.space1),
          Text(highlight.label, style: AppTypography.caption.copyWith(fontWeight: FontWeight.w700)),
        ],
      ),
    );
  }
}
