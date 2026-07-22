import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';

/// Loading skeleton matching [OrderCard]'s geometry — same gentle
/// opacity-pulse convention as the Store Module's `ProductSkeletonCard`
/// (own copy, no external shimmer package in the approved list).
class OrderSkeletonCard extends StatefulWidget {
  const OrderSkeletonCard({super.key});

  @override
  State<OrderSkeletonCard> createState() => _OrderSkeletonCardState();
}

class _OrderSkeletonCardState extends State<OrderSkeletonCard> with SingleTickerProviderStateMixin {
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
        padding: const EdgeInsets.all(AppSpacing.space3),
        decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(AppRadius.lg)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              children: <Widget>[
                _Block(width: 56, height: 56, radius: AppRadius.md),
                const SizedBox(width: AppSpacing.space3),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      const _Block(width: 120, height: 14),
                      const SizedBox(height: AppSpacing.space2),
                      const _Block(width: 80, height: 12),
                    ],
                  ),
                ),
                const _Block(width: 64, height: 20, radius: AppRadius.sm),
              ],
            ),
            const SizedBox(height: AppSpacing.space3),
            const _Block(width: double.infinity, height: 14),
            const SizedBox(height: AppSpacing.space3),
            const _Block(width: double.infinity, height: 40),
          ],
        ),
      ),
    );
  }
}

class _Block extends StatelessWidget {
  const _Block({required this.width, required this.height, this.radius = AppRadius.sm});

  final double width;
  final double height;
  final double radius;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(color: AppColors.border, borderRadius: BorderRadius.circular(radius)),
    );
  }
}
