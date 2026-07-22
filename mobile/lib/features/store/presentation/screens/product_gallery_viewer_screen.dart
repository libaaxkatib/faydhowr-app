import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../domain/entities/store_entities.dart';
import '../providers/store_providers.dart';

/// Part of STORE MODULE — FROZEN ✅ (see `StoreScreen`). Visual/UX design
/// approved final — no further UI changes without an explicit new request.
///
/// Product Gallery / Image Viewer (S-023, docs/05_UI_UX_Design.md §5.8) —
/// full-screen immersive pager, close control, swipe between images, no
/// decorative filters.
class ProductGalleryViewerScreen extends ConsumerStatefulWidget {
  const ProductGalleryViewerScreen({required this.productId, this.initialIndex = 0, super.key});

  final String productId;
  final int initialIndex;

  @override
  ConsumerState<ProductGalleryViewerScreen> createState() => _ProductGalleryViewerScreenState();
}

class _ProductGalleryViewerScreenState extends ConsumerState<ProductGalleryViewerScreen> {
  late final PageController _controller = PageController(initialPage: widget.initialIndex);
  late int _index = widget.initialIndex;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final ProductDetail? detail = ref.watch(productDetailsByIdProvider)[widget.productId];
    final List<ProductGalleryItem> gallery = detail?.gallery ?? const <ProductGalleryItem>[];

    return Scaffold(
      backgroundColor: Colors.black,
      body: SafeArea(
        child: Stack(
          children: <Widget>[
            if (gallery.isEmpty)
              const Center(child: Icon(Icons.image_outlined, color: Colors.white54, size: 64))
            else
              PageView.builder(
                controller: _controller,
                onPageChanged: (int index) => setState(() => _index = index),
                itemCount: gallery.length,
                itemBuilder: (BuildContext context, int index) {
                  return Semantics(
                    label: gallery[index].label,
                    image: true,
                    child: InteractiveViewer(
                      minScale: 1,
                      maxScale: 4,
                      child: Center(
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                              colors: <Color>[
                                AppColors.primary.withValues(alpha: 0.35),
                                AppColors.secondary.withValues(alpha: 0.35),
                              ],
                            ),
                          ),
                          child: const Padding(
                            padding: EdgeInsets.all(120),
                            child: Icon(Icons.image_outlined, color: Colors.white, size: 64),
                          ),
                        ),
                      ),
                    ),
                  );
                },
              ),
            Positioned(
              top: 8,
              left: 8,
              child: Semantics(
                button: true,
                label: 'Close image viewer',
                child: IconButton(
                  icon: const Icon(Icons.close, color: Colors.white),
                  onPressed: () => Navigator.of(context).pop(),
                ),
              ),
            ),
            if (gallery.length > 1)
              Positioned(
                bottom: 24,
                left: 0,
                right: 0,
                child: Text(
                  '${_index + 1} / ${gallery.length}',
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
