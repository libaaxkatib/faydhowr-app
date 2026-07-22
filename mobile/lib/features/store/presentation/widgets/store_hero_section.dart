import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';

/// STORE HERO — FROZEN ✅
///
/// Approved final. Do not modify layout, gradient, image position/size,
/// blend, icon, text, spacing, typography, shadows, border radius, or the
/// floating `StoreSearchBar` pairing without an explicit new request — this
/// component's visual design is signed off, not a work-in-progress.
///
/// Store Home hero — same Dark Blue → Turquoise gradient panel language as
/// `ServiceHeroSection` (Services Module), reused here for visual
/// consistency across the app's two catalog modules.
///
/// A decorative product photo (`assets/ui_reference/store_hero_products.png`)
/// is anchored to the right edge, faded into the gradient via a left-edge
/// [ShaderMask] rather than pasted with a hard border — the photo's own
/// background is close enough to [AppColors.primary] that the fade reads as
/// one continuous surface. Purely decorative: no semantics, no tap target,
/// scales with the hero's own responsive width (a fraction of it, not a
/// fixed pixel size), and is clipped to the hero's rounded corners by the
/// outer `Container`'s `clipBehavior` — never stretched, since it renders at
/// `BoxFit.cover` within its own reserved band. The `-imageWidth * 0.08`
/// right offset and the `0.40` fade stop are approved final tuning (image
/// pushed clear of the text column; blend softened) — not placeholders.
class StoreHeroSection extends StatelessWidget {
  const StoreHeroSection({super.key});

  static const double _imageWidthFraction = 0.38;
  static const double _textMaxWidthFraction = 0.62;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.fromLTRB(AppSpacing.space3, AppSpacing.space3, AppSpacing.space3, 0),
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: <Color>[AppColors.primary, AppColors.secondary],
        ),
        borderRadius: BorderRadius.circular(AppRadius.xl),
        boxShadow: <BoxShadow>[
          BoxShadow(
            color: AppColors.primary.withValues(alpha: 0.20),
            blurRadius: 20,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: LayoutBuilder(
        builder: (BuildContext context, BoxConstraints constraints) {
          final double imageWidth = constraints.maxWidth * _imageWidthFraction;

          return Stack(
            children: <Widget>[
              Positioned(
                top: 0,
                bottom: 0,
                right: -imageWidth * 0.08,
                width: imageWidth,
                child: IgnorePointer(
                  child: ShaderMask(
                    shaderCallback: (Rect rect) => const LinearGradient(
                      begin: Alignment.centerLeft,
                      end: Alignment.centerRight,
                      colors: <Color>[Colors.transparent, Colors.white],
                      stops: <double>[0.0, 0.40],
                    ).createShader(rect),
                    blendMode: BlendMode.dstIn,
                    child: Image.asset(
                      'assets/ui_reference/store_hero_products.png',
                      fit: BoxFit.cover,
                      alignment: Alignment.centerRight,
                    ),
                  ),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  AppSpacing.space4,
                  AppSpacing.space5,
                  AppSpacing.space4,
                  AppSpacing.space6 + AppSpacing.space3,
                ),
                child: ConstrainedBox(
                  constraints: BoxConstraints(maxWidth: constraints.maxWidth * _textMaxWidthFraction),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Semantics(
                        label: 'Store',
                        image: true,
                        child: Container(
                          width: 56,
                          height: 56,
                          decoration: const BoxDecoration(color: AppColors.white, shape: BoxShape.circle),
                          alignment: Alignment.center,
                          child: const Icon(Icons.storefront_outlined, color: AppColors.primary, size: 28),
                        ),
                      ),
                      const SizedBox(height: AppSpacing.space4),
                      Semantics(
                        header: true,
                        child: Text(
                          'Cleaning Products',
                          style: AppTypography.heading1.copyWith(color: AppColors.white),
                        ),
                      ),
                      const SizedBox(height: AppSpacing.space2),
                      Text(
                        'Professional cleaning products you can trust.',
                        style: AppTypography.body.copyWith(color: AppColors.white.withValues(alpha: 0.85)),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
