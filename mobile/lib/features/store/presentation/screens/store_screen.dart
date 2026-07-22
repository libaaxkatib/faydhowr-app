import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/store_entities.dart';
import '../providers/store_providers.dart';
import '../widgets/product_card.dart';
import '../widgets/product_skeleton_card.dart';
import '../widgets/store_category_chips.dart';
import '../widgets/state_icon_box.dart';
import '../widgets/store_hero_section.dart';
import '../widgets/store_search_bar.dart';

/// STORE MODULE — FROZEN ✅
///
/// Approved final (visual + UX). Do not make further UI/UX changes to any
/// Store screen (Store Catalog, Product Details, Gallery Viewer, Cart) or
/// their widgets without an explicit new request — this pass is signed
/// off, not in progress. Business logic, Riverpod, routing, and APIs were
/// never in scope for these passes and remain governed by the docs below
/// as always.
///
/// Store Catalog (S-020, docs/05_UI_UX_Design.md §4.6) — replaces the
/// Foundation placeholder entirely. Browse priced products: search,
/// category filter, Featured Products, and a paginated Product Grid with
/// pull-to-refresh, infinite scroll, loading skeletons, empty state, and a
/// full-panel error/retry state.
///
/// Visual composition (hero + floating search bar, icon-tile categories,
/// dot-paged Featured carousel) follows the approved premium UI reference
/// (`assets/ui_reference/store_home.png`) — inspiration for layout/spacing
/// only; every color/type/radius/spacing value still comes from
/// `core/theme`, and no business logic, API, or routing changed.
class StoreScreen extends ConsumerStatefulWidget {
  const StoreScreen({super.key});

  @override
  ConsumerState<StoreScreen> createState() => _StoreScreenState();
}

class _StoreScreenState extends ConsumerState<StoreScreen> {
  final ScrollController _scrollController = ScrollController();

  /// Half the search bar's height overlaps the hero below it — mirrors the
  /// reference's floating-search-bar composition.
  static const double _searchBarOverlap = 28;

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_maybeLoadMore);
  }

  @override
  void dispose() {
    _scrollController.removeListener(_maybeLoadMore);
    _scrollController.dispose();
    super.dispose();
  }

  void _maybeLoadMore() {
    if (_scrollController.position.pixels >= _scrollController.position.maxScrollExtent - 200) {
      ref.read(storeCatalogProvider.notifier).loadMore();
    }
  }

  /// Compact phones: 2 columns; tablets/large-screen devices: 3 — scales
  /// layout density only, never the brand tokens themselves
  /// (docs/09_Flutter_Architecture.md §13).
  int _columnsForWidth(double width) => width >= 700 ? 3 : 2;

  void _clearFilters() {
    ref.read(storeSearchQueryProvider.notifier).update('');
    ref.read(selectedProductCategoryProvider.notifier).select(null);
  }

  @override
  Widget build(BuildContext context) {
    final AsyncValue<List<ProductPreview>> catalogState = ref.watch(storeCatalogProvider);
    final StoreCatalogNotifier notifier = ref.watch(storeCatalogProvider.notifier);
    final List<ProductPreview> featured = ref.watch(featuredProductsProvider);
    final bool isLoadingMore = ref.watch(storeLoadingMoreProvider);
    final bool isFiltering =
        ref.watch(storeSearchQueryProvider).isNotEmpty || ref.watch(selectedProductCategoryProvider) != null;
    final int columns = _columnsForWidth(MediaQuery.sizeOf(context).width);

    return Scaffold(
      backgroundColor: AppColors.background,
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: notifier.refresh,
          child: CustomScrollView(
            controller: _scrollController,
            slivers: <Widget>[
              SliverToBoxAdapter(
                child: Stack(
                  clipBehavior: Clip.none,
                  children: <Widget>[
                    const StoreHeroSection(),
                    Positioned(
                      bottom: -_searchBarOverlap,
                      left: 0,
                      right: 0,
                      child: const StoreSearchBar(),
                    ),
                  ],
                ),
              ),
              SliverToBoxAdapter(child: const SizedBox(height: _searchBarOverlap + AppSpacing.space4)),
              const SliverToBoxAdapter(child: SectionHeader(title: 'Categories')),
              const SliverToBoxAdapter(child: StoreCategoryChips()),
              if (!isFiltering && featured.isNotEmpty) _FeaturedSliver(products: featured),
              ...catalogState.when(
                data: (List<ProductPreview> items) => _dataSlivers(items, columns, isLoadingMore),
                loading: () => _loadingSlivers(columns),
                error: (Object error, StackTrace _) => <Widget>[_ErrorSliver(onRetry: notifier.refresh)],
              ),
              const SliverToBoxAdapter(child: SizedBox(height: AppSpacing.space5)),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _dataSlivers(List<ProductPreview> items, int columns, bool isLoadingMore) {
    if (items.isEmpty) {
      return <Widget>[_EmptySliver(onClearFilters: _clearFilters)];
    }
    return <Widget>[
      const SliverToBoxAdapter(child: SectionHeader(title: 'All Products')),
      SliverPadding(
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
        sliver: SliverGrid(
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: columns,
            crossAxisSpacing: AppSpacing.space3,
            mainAxisSpacing: AppSpacing.space3,
            childAspectRatio: 0.60,
          ),
          delegate: SliverChildBuilderDelegate(
            (BuildContext context, int index) => ProductCard(
              product: items[index],
              onTap: () => context.push('/store/${items[index].id}'),
            ),
            childCount: items.length,
          ),
        ),
      ),
      if (isLoadingMore)
        const SliverToBoxAdapter(
          child: Padding(
            padding: EdgeInsets.symmetric(vertical: AppSpacing.space4),
            child: Center(child: CircularProgressIndicator(strokeWidth: 2.4)),
          ),
        ),
    ];
  }

  List<Widget> _loadingSlivers(int columns) {
    return <Widget>[
      const SliverToBoxAdapter(child: SectionHeader(title: 'All Products')),
      SliverPadding(
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
        sliver: SliverGrid(
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: columns,
            crossAxisSpacing: AppSpacing.space3,
            mainAxisSpacing: AppSpacing.space3,
            childAspectRatio: 0.60,
          ),
          delegate: SliverChildBuilderDelegate(
            (BuildContext context, int index) => const ProductSkeletonCard(),
            childCount: columns * 3,
          ),
        ),
      ),
    ];
  }
}

class _FeaturedSliver extends StatefulWidget {
  const _FeaturedSliver({required this.products});

  final List<ProductPreview> products;

  @override
  State<_FeaturedSliver> createState() => _FeaturedSliverState();
}

class _FeaturedSliverState extends State<_FeaturedSliver> {
  static const double _itemExtent = 176;
  final ScrollController _controller = ScrollController();
  int _activeIndex = 0;

  @override
  void initState() {
    super.initState();
    _controller.addListener(_updateActiveIndex);
  }

  @override
  void dispose() {
    _controller.removeListener(_updateActiveIndex);
    _controller.dispose();
    super.dispose();
  }

  void _updateActiveIndex() {
    final int next = (_controller.offset / _itemExtent).round().clamp(0, widget.products.length - 1);
    if (next != _activeIndex) {
      setState(() => _activeIndex = next);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SliverToBoxAdapter(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          const SectionHeader(title: 'Featured Products'),
          SizedBox(
            height: 260,
            child: ListView.builder(
              controller: _controller,
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
              itemCount: widget.products.length,
              itemBuilder: (BuildContext context, int index) => Padding(
                padding: const EdgeInsets.only(right: AppSpacing.space3),
                child: SizedBox(
                  width: 160,
                  child: ProductCard(
                    product: widget.products[index],
                    onTap: () => context.push('/store/${widget.products[index].id}'),
                  ),
                ),
              ),
            ),
          ),
          if (widget.products.length > 1) ...<Widget>[
            const SizedBox(height: AppSpacing.space2),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: <Widget>[
                for (int i = 0; i < widget.products.length; i++)
                  AnimatedContainer(
                    duration: const Duration(milliseconds: 200),
                    margin: const EdgeInsets.symmetric(horizontal: 3),
                    width: i == _activeIndex ? 18 : 6,
                    height: 6,
                    decoration: BoxDecoration(
                      color: i == _activeIndex ? AppColors.secondary : AppColors.border,
                      borderRadius: BorderRadius.circular(999),
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _EmptySliver extends StatelessWidget {
  const _EmptySliver({required this.onClearFilters});

  final VoidCallback onClearFilters;

  @override
  Widget build(BuildContext context) {
    return SliverFillRemaining(
      hasScrollBody: false,
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.space4),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              const StateIconBox(icon: Icons.inventory_2_outlined),
              const SizedBox(height: AppSpacing.space3),
              Text('No products found', style: AppTypography.heading3),
              const SizedBox(height: AppSpacing.space1),
              Text(
                'Try adjusting your search or category filter.',
                textAlign: TextAlign.center,
                style: AppTypography.body.copyWith(color: AppColors.textSecondary),
              ),
              const SizedBox(height: AppSpacing.space4),
              FilledButton(onPressed: onClearFilters, child: const Text('Clear Filters')),
            ],
          ),
        ),
      ),
    );
  }
}

class _ErrorSliver extends StatelessWidget {
  const _ErrorSliver({required this.onRetry});

  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    return SliverFillRemaining(
      hasScrollBody: false,
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.space4),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              const StateIconBox(icon: Icons.error_outline, color: AppColors.error),
              const SizedBox(height: AppSpacing.space3),
              Text(
                'Something went wrong loading products.',
                textAlign: TextAlign.center,
                style: AppTypography.body.copyWith(color: AppColors.textSecondary),
              ),
              const SizedBox(height: AppSpacing.space3),
              FilledButton(onPressed: onRetry, child: const Text('Try Again')),
            ],
          ),
        ),
      ),
    );
  }
}
