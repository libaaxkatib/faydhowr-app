import 'package:flutter/material.dart';

/// Plain entities for the Store Module (V1) — mock data only, no
/// repository/backend yet, matching the Home/Services/Booking Modules'
/// established pattern. Field names mirror the approved API contract
/// (docs/06_API_Design.md §7.1–7.2) so a future repository swap is a
/// drop-in, not a reshape.

/// Server-computed availability state (`availability_status`) — never
/// re-derived client-side from a raw stock number.
enum AvailabilityStatus {
  inStock('In Stock'),
  lowStock('Low Stock'),
  outOfStock('Out of Stock');

  const AvailabilityStatus(this.label);

  final String label;
}

/// Optional marketing badge (docs/02_SRS.md §8.4). No "Favorite"/rating
/// badge exists here — product favorites and reviews are out of V1 scope.
enum ProductBadge {
  newArrival('New'),
  bestSeller('Best Seller'),
  popular('Popular'),
  limitedStock('Limited Stock');

  const ProductBadge(this.label);

  final String label;
}

@immutable
class ProductCategoryPreview {
  const ProductCategoryPreview({required this.id, required this.name, required this.icon});

  final String id;
  final String name;
  final IconData icon;
}

/// Optional quantity tier pricing (docs/02_SRS.md FR-027, "Should").
@immutable
class ProductPriceTier {
  const ProductPriceTier({required this.quantityLabel, required this.priceLabel});

  /// e.g. "3+".
  final String quantityLabel;

  /// e.g. "7.50 / Bottle".
  final String priceLabel;
}

/// Product Card / catalog-level fields — image, name, Selling Price with
/// unit, availability, optional marketing badge (docs/05_UI_UX_Design.md
/// §5.4). Cost Price is never modeled here — it is never sent to customer
/// clients (docs/02_SRS.md §8.5) and has no representation in this app.
@immutable
class ProductPreview {
  const ProductPreview({
    required this.id,
    required this.categoryId,
    required this.name,
    required this.sellingPrice,
    required this.unit,
    required this.availability,
    required this.stockCount,
    this.badge,
    this.isFeatured = false,
  });

  final String id;
  final String categoryId;
  final String name;

  /// Selling Price — customer-facing only (docs/02_SRS.md §8.5).
  final double sellingPrice;

  /// Selling unit (Piece, Pack, Box, Bottle, ...) — docs/02_SRS.md §8.4.
  final String unit;
  final AvailabilityStatus availability;

  /// Backs the quantity selector's ceiling; never displayed as a raw
  /// number to the customer (only [availability] is shown).
  final int stockCount;
  final ProductBadge? badge;

  /// Mirrors the backend `products.is_featured` column (docs/03_Database_Design.md
  /// §5.2) — manually curated by the business, never client re-sorted.
  final bool isFeatured;

  /// e.g. "$8.50 / Bottle" — mandatory visible price (docs/05_UI_UX_Design.md §5.4).
  String get priceLabel => '\$${sellingPrice.toStringAsFixed(2)} / $unit';
}

@immutable
class ProductGalleryItem {
  const ProductGalleryItem({required this.label});

  final String label;
}

@immutable
class ProductDetail {
  const ProductDetail({
    required this.preview,
    required this.sku,
    required this.description,
    required this.gallery,
    required this.specifications,
    this.priceTiers = const <ProductPriceTier>[],
    this.allowOptionalQuotation = false,
    this.relatedProductIds = const <String>[],
  });

  final ProductPreview preview;
  final String sku;
  final String description;
  final List<ProductGalleryItem> gallery;
  final List<String> specifications;
  final List<ProductPriceTier> priceTiers;

  /// Product Quotation source flag (docs/02_SRS.md §8.6, §06_API_Design.md
  /// §7.7) — purely gates whether "Request Quotation" is shown; submission
  /// itself uses the shared Quotation Module, not built in this module.
  final bool allowOptionalQuotation;
  final List<String> relatedProductIds;
}

/// One Cart line — quantity only; display fields (name, price, image) are
/// resolved from the product catalog by [productId], the same "snapshot by
/// lookup" approach Booking uses for its selected service.
@immutable
class CartLine {
  const CartLine({required this.productId, required this.quantity});

  final String productId;
  final int quantity;

  CartLine copyWith({int? quantity}) => CartLine(productId: productId, quantity: quantity ?? this.quantity);
}
