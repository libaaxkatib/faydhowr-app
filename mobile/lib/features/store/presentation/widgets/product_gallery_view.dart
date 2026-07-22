import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../domain/entities/store_entities.dart';
import 'product_marketing_badge.dart';

/// Swipeable product image gallery on Product Details (docs/05_UI_UX_Design.md
/// §4.6 — "Swipeable image gallery (placeholders + pagination; zoom-ready)"),
/// with a thumbnail selector strip beneath it (premium reference:
/// `assets/ui_reference/product_detail.png`). Tapping the main image or a
/// thumbnail opens/selects the same full-screen Image Viewer (S-023) /
/// page — no new navigation destinations, purely a richer selector for the
/// existing gallery.
class ProductGalleryView extends StatefulWidget {
  const ProductGalleryView({required this.productId, required this.gallery, this.badge, super.key});

  final String productId;
  final List<ProductGalleryItem> gallery;

  /// Optional marketing badge, overlaid top-left of the hero image — same
  /// data Product Details already renders, just positioned on the image per
  /// the reference instead of inline with the title.
  final ProductBadge? badge;

  @override
  State<ProductGalleryView> createState() => _ProductGalleryViewState();
}

class _ProductGalleryViewState extends State<ProductGalleryView> {
  final PageController _controller = PageController();
  int _index = 0;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _openViewer() {
    context.push('/store/${widget.productId}/gallery', extra: _index);
  }

  void _selectThumbnail(int index) {
    _controller.animateToPage(index, duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        AspectRatio(
          aspectRatio: 1.1,
          child: Stack(
            children: <Widget>[
              ClipRRect(
                borderRadius: BorderRadius.circular(AppRadius.xl),
                child: PageView.builder(
                  controller: _controller,
                  onPageChanged: (int index) => setState(() => _index = index),
                  itemCount: widget.gallery.length,
                  itemBuilder: (BuildContext context, int index) {
                    return GestureDetector(
                      onTap: _openViewer,
                      child: Semantics(
                        label: widget.gallery[index].label,
                        image: true,
                        button: true,
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                              colors: <Color>[
                                AppColors.primary.withValues(alpha: 0.08),
                                AppColors.secondary.withValues(alpha: 0.12),
                              ],
                            ),
                          ),
                          child: const Center(
                            child: Icon(Icons.image_outlined, color: AppColors.primary, size: 48),
                          ),
                        ),
                      ),
                    );
                  },
                ),
              ),
              if (widget.badge != null)
                Positioned(
                  top: AppSpacing.space3,
                  left: AppSpacing.space3,
                  child: ProductMarketingBadge(badge: widget.badge!),
                ),
              if (widget.gallery.length > 1)
                Positioned(
                  bottom: 12,
                  left: 0,
                  right: 0,
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: <Widget>[
                      for (int i = 0; i < widget.gallery.length; i++) _GalleryDot(active: i == _index),
                    ],
                  ),
                ),
            ],
          ),
        ),
        if (widget.gallery.length > 1) ...<Widget>[
          const SizedBox(height: AppSpacing.space3),
          SizedBox(
            height: 64,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: widget.gallery.length,
              separatorBuilder: (BuildContext context, int index) => const SizedBox(width: AppSpacing.space2),
              itemBuilder: (BuildContext context, int index) {
                final bool isActive = index == _index;
                return Semantics(
                  button: true,
                  selected: isActive,
                  label: widget.gallery[index].label,
                  child: InkWell(
                    borderRadius: BorderRadius.circular(AppRadius.md),
                    onTap: () => _selectThumbnail(index),
                    child: Container(
                      width: 64,
                      height: 64,
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(AppRadius.md),
                        border: Border.all(color: isActive ? AppColors.primary : AppColors.border, width: 2),
                        gradient: LinearGradient(
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                          colors: <Color>[
                            AppColors.primary.withValues(alpha: 0.06),
                            AppColors.secondary.withValues(alpha: 0.10),
                          ],
                        ),
                      ),
                      alignment: Alignment.center,
                      child: Icon(Icons.image_outlined, color: AppColors.primary, size: 20),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ],
    );
  }
}

class _GalleryDot extends StatelessWidget {
  const _GalleryDot({required this.active});

  final bool active;

  @override
  Widget build(BuildContext context) {
    return AnimatedContainer(
      duration: const Duration(milliseconds: 200),
      margin: const EdgeInsets.symmetric(horizontal: 3),
      width: active ? 18 : 6,
      height: 6,
      decoration: BoxDecoration(
        color: active ? AppColors.white : AppColors.white.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(999),
      ),
    );
  }
}
