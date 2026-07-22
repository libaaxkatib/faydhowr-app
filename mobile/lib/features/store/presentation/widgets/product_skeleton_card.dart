import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';

/// Loading skeleton matching [ProductCard]'s geometry exactly, so the grid
/// doesn't jump when real cards replace it. A gentle opacity pulse (no
/// external shimmer package — none is in the approved package list,
/// docs/09_Flutter_Architecture.md §18) stands in for a shimmer sweep.
class ProductSkeletonCard extends StatefulWidget {
  const ProductSkeletonCard({super.key});

  @override
  State<ProductSkeletonCard> createState() => _ProductSkeletonCardState();
}

class _ProductSkeletonCardState extends State<ProductSkeletonCard> with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _opacity;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(vsync: this, duration: const Duration(milliseconds: 900))
      ..repeat(reverse: true);
    _opacity = Tween<double>(begin: 0.4, end: 1).animate(CurvedAnimation(parent: _controller, curve: Curves.easeInOut));
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _opacity,
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(AppRadius.lg),
        ),
        clipBehavior: Clip.antiAlias,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            AspectRatio(
              aspectRatio: 1.3,
              child: DecoratedBox(decoration: BoxDecoration(color: AppColors.border.withValues(alpha: 0.6))),
            ),
            Padding(
              padding: const EdgeInsets.all(AppSpacing.space2),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  const _Block(width: 96, height: 14),
                  const SizedBox(height: AppSpacing.space2),
                  const _Block(width: 60, height: 14),
                  const SizedBox(height: AppSpacing.space2),
                  const _Block(width: 70, height: 16),
                  const SizedBox(height: AppSpacing.space2),
                  const _Block(width: double.infinity, height: 40),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Block extends StatelessWidget {
  const _Block({required this.width, required this.height});

  final double width;
  final double height;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: AppColors.border,
        borderRadius: BorderRadius.circular(AppRadius.sm),
      ),
    );
  }
}
