import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/store_mock_data.dart';
import '../../domain/entities/store_entities.dart';

/// Mock-backed catalog providers for the Store Module (V1) — mirrors the
/// Home/Services/Booking Modules' pattern of trivial `Provider`s wrapping
/// constant mock data, since there is no repository/backend yet.
final productCategoryCatalogProvider = Provider<List<ProductCategoryPreview>>((ref) => mockProductCategories);

final productCatalogProvider = Provider<List<ProductPreview>>((ref) => mockProducts);

/// Manually curated Featured Products for Store Home (docs/03_Database_Design.md
/// §5.2) — server-provided order in a real API; here, catalog order among
/// `isFeatured` items is preserved rather than re-sorted.
final featuredProductsProvider = Provider<List<ProductPreview>>(
  (ref) => ref.watch(productCatalogProvider).where((ProductPreview p) => p.isFeatured).toList(),
);

final productDetailsByIdProvider = Provider<Map<String, ProductDetail>>(
  (ref) => <String, ProductDetail>{
    for (final ProductDetail detail in mockProductDetails) detail.preview.id: detail,
  },
);

/// Free-text search across the Store Catalog. Hand-written `Notifier` —
/// same pattern as `ServicesSearchQueryNotifier`.
class StoreSearchQueryNotifier extends Notifier<String> {
  @override
  String build() => '';

  void update(String value) => state = value;
}

final storeSearchQueryProvider = NotifierProvider<StoreSearchQueryNotifier, String>(StoreSearchQueryNotifier.new);

/// Selected category chip — `null` means "All". Same pattern as
/// `SelectedServiceCategoryNotifier`.
class SelectedProductCategoryNotifier extends Notifier<String?> {
  @override
  String? build() => null;

  void select(String? categoryId) => state = categoryId;
}

final selectedProductCategoryProvider = NotifierProvider<SelectedProductCategoryNotifier, String?>(
  SelectedProductCategoryNotifier.new,
);

/// Store Catalog content after search + category filter are applied. The
/// only supported filter is category (docs/06_API_Design.md §7.1 — the
/// Product List query accepts `category_id`, `page`, `per_page` only; no
/// price/sort/in-stock filter exists in the approved API, so none is
/// invented here).
final filteredProductsProvider = Provider<List<ProductPreview>>((ref) {
  final String query = ref.watch(storeSearchQueryProvider).trim().toLowerCase();
  final String? categoryId = ref.watch(selectedProductCategoryProvider);
  final List<ProductPreview> all = ref.watch(productCatalogProvider);

  return all.where((ProductPreview product) {
    final bool matchesCategory = categoryId == null || product.categoryId == categoryId;
    final bool matchesQuery = query.isEmpty || product.name.toLowerCase().contains(query);
    return matchesCategory && matchesQuery;
  }).toList();
});

/// Paginated catalog load — the first module to model real Loading / Data /
/// Error via `AsyncValue` (docs/09_Flutter_Architecture.md §4.1), since
/// Home/Services/Booking never needed it against synchronous mock data.
/// There is still no backend: the "fetch" is a fixed artificial delay over
/// [filteredProductsProvider], not a real network call, so the Error branch
/// has no organic trigger in this mock phase — the same "prepared, not yet
/// wired to a real failure" status as Booking's Continue-button loading
/// state. Rebuilds automatically whenever search/category filters change
/// (via `ref.watch(filteredProductsProvider)`), which also resets paging.
class StoreCatalogNotifier extends AsyncNotifier<List<ProductPreview>> {
  static const int pageSize = 8;

  int _loadedCount = pageSize;
  bool hasMore = true;

  @override
  Future<List<ProductPreview>> build() async {
    final List<ProductPreview> filtered = ref.watch(filteredProductsProvider);
    _loadedCount = pageSize;
    await Future<void>.delayed(const Duration(milliseconds: 500));
    hasMore = _loadedCount < filtered.length;
    return filtered.take(_loadedCount).toList();
  }

  /// Infinite-scroll "next page". No-ops while already loading or when the
  /// filtered list is exhausted. Keeps the existing list visible in [state]
  /// throughout — [storeLoadingMoreProvider] drives the small bottom
  /// spinner instead of the full-grid loading state, so this never needs
  /// Riverpod's package-internal `AsyncValue.copyWithPrevious`.
  Future<void> loadMore() async {
    if (!hasMore || ref.read(storeLoadingMoreProvider)) {
      return;
    }
    final List<ProductPreview> filtered = ref.read(filteredProductsProvider);
    ref.read(storeLoadingMoreProvider.notifier).set(true);
    await Future<void>.delayed(const Duration(milliseconds: 500));
    _loadedCount = (_loadedCount + pageSize).clamp(0, filtered.length);
    hasMore = _loadedCount < filtered.length;
    state = AsyncData<List<ProductPreview>>(filtered.take(_loadedCount).toList());
    ref.read(storeLoadingMoreProvider.notifier).set(false);
  }

  /// Pull-to-refresh: resets paging back to the first page. `RefreshIndicator`
  /// already shows its own spinner while this future runs, so the previous
  /// list simply stays on screen (stale-while-revalidate) rather than
  /// flashing the full-grid loading state.
  Future<void> refresh() async {
    final List<ProductPreview> filtered = ref.read(filteredProductsProvider);
    _loadedCount = pageSize;
    state = await AsyncValue.guard(() async {
      await Future<void>.delayed(const Duration(milliseconds: 700));
      hasMore = _loadedCount < filtered.length;
      return filtered.take(_loadedCount).toList();
    });
  }
}

final storeCatalogProvider = AsyncNotifierProvider<StoreCatalogNotifier, List<ProductPreview>>(
  StoreCatalogNotifier.new,
);

/// Whether the next page is currently being fetched — separate from
/// [storeCatalogProvider]'s own `AsyncValue` so the existing grid can stay
/// on screen with only a small bottom spinner during load-more.
class StoreLoadingMoreNotifier extends Notifier<bool> {
  @override
  bool build() => false;

  void set(bool value) => state = value;
}

final storeLoadingMoreProvider = NotifierProvider<StoreLoadingMoreNotifier, bool>(StoreLoadingMoreNotifier.new);

/// Cart line state. Hand-written `Notifier`, same pattern as
/// `BookingDraftNotifier` — not `family`, single cart per app instance.
class CartNotifier extends Notifier<List<CartLine>> {
  @override
  List<CartLine> build() => const <CartLine>[];

  void addToCart(String productId, {int quantity = 1}) {
    final int index = state.indexWhere((CartLine line) => line.productId == productId);
    if (index == -1) {
      state = <CartLine>[...state, CartLine(productId: productId, quantity: quantity)];
    } else {
      state = <CartLine>[
        for (final CartLine line in state)
          if (line.productId == productId) line.copyWith(quantity: line.quantity + quantity) else line,
      ];
    }
  }

  /// Removes the line when [quantity] drops to zero or below — matches the
  /// stepper's documented floor (docs/05_UI_UX_Design.md §10.2: minimum 1),
  /// so 0 is only reached via explicit Remove, not the `−` stepper itself.
  void updateQuantity(String productId, int quantity) {
    if (quantity <= 0) {
      removeItem(productId);
      return;
    }
    state = <CartLine>[
      for (final CartLine line in state)
        if (line.productId == productId) line.copyWith(quantity: quantity) else line,
    ];
  }

  void removeItem(String productId) {
    state = state.where((CartLine line) => line.productId != productId).toList();
  }

  void clear() => state = const <CartLine>[];
}

final cartProvider = NotifierProvider<CartNotifier, List<CartLine>>(CartNotifier.new);

/// Total item quantity across all lines — drives the bottom-nav Cart badge.
final cartItemCountProvider = Provider<int>((ref) {
  return ref.watch(cartProvider).fold<int>(0, (int sum, CartLine line) => sum + line.quantity);
});

/// Cart subtotal, repriced against the current catalog Selling Price on
/// every read (docs/06_API_Design.md §7.5 — "Reprice against Selling Price
/// on read"), not a stale price captured at add-to-cart time.
final cartSubtotalProvider = Provider<double>((ref) {
  final List<CartLine> lines = ref.watch(cartProvider);
  final Map<String, ProductPreview> catalog = <String, ProductPreview>{
    for (final ProductPreview p in ref.watch(productCatalogProvider)) p.id: p,
  };
  return lines.fold<double>(
    0,
    (double sum, CartLine line) => sum + (catalog[line.productId]?.sellingPrice ?? 0) * line.quantity,
  );
});
