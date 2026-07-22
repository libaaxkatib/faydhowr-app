import 'package:flutter/material.dart';

import '../domain/entities/store_entities.dart';

/// Mock data for the Store Module (V1). No backend, no API — matches the
/// Home/Services/Booking Modules' established pattern for a module with no
/// repository yet.
///
/// [mockProductCategories] is the official approved V1 Store category list
/// (docs/02_SRS.md §8.1) — exactly these 5 categories, no others. Product
/// copy (descriptions/specifications) is representative placeholder
/// content, not final business content.

const List<ProductCategoryPreview> mockProductCategories = <ProductCategoryPreview>[
  ProductCategoryPreview(id: 'chemicals', name: 'Cleaning Chemicals', icon: Icons.science_outlined),
  ProductCategoryPreview(id: 'tools', name: 'Cleaning Tools', icon: Icons.handyman_outlined),
  ProductCategoryPreview(id: 'accessories', name: 'Cleaning Accessories', icon: Icons.inventory_2_outlined),
  ProductCategoryPreview(id: 'ppe', name: 'Personal Protective Equipment (PPE)', icon: Icons.health_and_safety_outlined),
  ProductCategoryPreview(id: 'air-fresheners', name: 'Air Fresheners', icon: Icons.air_outlined),
];

const List<ProductPreview> mockProducts = <ProductPreview>[
  ProductPreview(
    id: 'multi-surface-cleaner',
    categoryId: 'chemicals',
    name: 'Multi-Surface Cleaner',
    sellingPrice: 8.50,
    unit: 'Bottle',
    availability: AvailabilityStatus.inStock,
    stockCount: 60,
    badge: ProductBadge.bestSeller,
    isFeatured: true,
  ),
  ProductPreview(
    id: 'floor-disinfectant',
    categoryId: 'chemicals',
    name: 'Floor Disinfectant',
    sellingPrice: 12.00,
    unit: 'Bottle',
    availability: AvailabilityStatus.lowStock,
    stockCount: 4,
  ),
  ProductPreview(
    id: 'glass-cleaner-spray',
    categoryId: 'chemicals',
    name: 'Glass Cleaner Spray',
    sellingPrice: 6.00,
    unit: 'Bottle',
    availability: AvailabilityStatus.inStock,
    stockCount: 40,
  ),
  ProductPreview(
    id: 'microfiber-mop-set',
    categoryId: 'tools',
    name: 'Microfiber Mop Set',
    sellingPrice: 18.00,
    unit: 'Piece',
    availability: AvailabilityStatus.inStock,
    stockCount: 25,
    badge: ProductBadge.newArrival,
    isFeatured: true,
  ),
  ProductPreview(
    id: 'scrub-brush-kit',
    categoryId: 'tools',
    name: 'Scrub Brush Kit',
    sellingPrice: 9.50,
    unit: 'Pack',
    availability: AvailabilityStatus.inStock,
    stockCount: 30,
  ),
  ProductPreview(
    id: 'telescopic-squeegee',
    categoryId: 'tools',
    name: 'Telescopic Squeegee',
    sellingPrice: 15.00,
    unit: 'Piece',
    availability: AvailabilityStatus.outOfStock,
    stockCount: 0,
  ),
  ProductPreview(
    id: 'cleaning-caddy-organizer',
    categoryId: 'accessories',
    name: 'Cleaning Caddy Organizer',
    sellingPrice: 22.00,
    unit: 'Piece',
    availability: AvailabilityStatus.inStock,
    stockCount: 15,
  ),
  ProductPreview(
    id: 'microfiber-cloth-pack',
    categoryId: 'accessories',
    name: 'Microfiber Cloth Pack',
    sellingPrice: 7.00,
    unit: 'Pack',
    availability: AvailabilityStatus.inStock,
    stockCount: 50,
    badge: ProductBadge.popular,
    isFeatured: true,
  ),
  ProductPreview(
    id: 'disposable-gloves-box',
    categoryId: 'ppe',
    name: 'Disposable Gloves Box',
    sellingPrice: 5.50,
    unit: 'Box',
    availability: AvailabilityStatus.inStock,
    stockCount: 45,
  ),
  ProductPreview(
    id: 'n95-protective-masks',
    categoryId: 'ppe',
    name: 'N95 Protective Masks',
    sellingPrice: 10.00,
    unit: 'Box',
    availability: AvailabilityStatus.lowStock,
    stockCount: 6,
  ),
  ProductPreview(
    id: 'lavender-air-freshener',
    categoryId: 'air-fresheners',
    name: 'Lavender Air Freshener',
    sellingPrice: 4.50,
    unit: 'Bottle',
    availability: AvailabilityStatus.inStock,
    stockCount: 35,
  ),
  ProductPreview(
    id: 'citrus-fresh-spray',
    categoryId: 'air-fresheners',
    name: 'Citrus Fresh Spray',
    sellingPrice: 4.00,
    unit: 'Bottle',
    availability: AvailabilityStatus.inStock,
    stockCount: 8,
    badge: ProductBadge.limitedStock,
  ),
];

List<ProductGalleryItem> _gallery(String productName) => <ProductGalleryItem>[
  ProductGalleryItem(label: '$productName — Photo 1'),
  ProductGalleryItem(label: '$productName — Photo 2'),
];

Map<String, ProductPreview> get _byId => <String, ProductPreview>{
  for (final ProductPreview p in mockProducts) p.id: p,
};

final List<ProductDetail> mockProductDetails = <ProductDetail>[
  ProductDetail(
    preview: _byId['multi-surface-cleaner']!,
    sku: 'SKU-CHEM-001',
    description:
        'An all-purpose cleaner that cuts through grease and grime on countertops, tiles, and most household '
        'surfaces — safe for daily use.',
    gallery: _gallery('Multi-Surface Cleaner'),
    specifications: const <String>['Volume: 750ml', 'Fragrance: Citrus', 'Suitable for: Kitchens, bathrooms, floors'],
    priceTiers: const <ProductPriceTier>[
      ProductPriceTier(quantityLabel: '3+', priceLabel: '7.50 / Bottle'),
      ProductPriceTier(quantityLabel: '6+', priceLabel: '6.75 / Bottle'),
    ],
    allowOptionalQuotation: true,
    relatedProductIds: const <String>['glass-cleaner-spray', 'floor-disinfectant'],
  ),
  ProductDetail(
    preview: _byId['floor-disinfectant']!,
    sku: 'SKU-CHEM-002',
    description: 'A hospital-grade disinfectant formulated for floors, eliminating common household germs and bacteria.',
    gallery: _gallery('Floor Disinfectant'),
    specifications: const <String>['Volume: 1L', 'Fragrance: Pine', 'Suitable for: Tile, vinyl, sealed wood'],
    relatedProductIds: const <String>['multi-surface-cleaner'],
  ),
  ProductDetail(
    preview: _byId['glass-cleaner-spray']!,
    sku: 'SKU-CHEM-003',
    description: 'A streak-free glass and mirror cleaner with a fast-drying, ammonia-free formula.',
    gallery: _gallery('Glass Cleaner Spray'),
    specifications: const <String>['Volume: 500ml', 'Ammonia-free', 'Suitable for: Glass, mirrors, screens'],
    relatedProductIds: const <String>['multi-surface-cleaner', 'microfiber-cloth-pack'],
  ),
  ProductDetail(
    preview: _byId['microfiber-mop-set']!,
    sku: 'SKU-TOOL-001',
    description: 'A washable microfiber mop with an adjustable handle and two reusable mop pads.',
    gallery: _gallery('Microfiber Mop Set'),
    specifications: const <String>['Handle length: 130cm (adjustable)', 'Includes: 2 washable mop pads'],
    relatedProductIds: const <String>['scrub-brush-kit', 'telescopic-squeegee'],
  ),
  ProductDetail(
    preview: _byId['scrub-brush-kit']!,
    sku: 'SKU-TOOL-002',
    description: 'A 3-piece scrub brush set covering grout, tile, and general household scrubbing needs.',
    gallery: _gallery('Scrub Brush Kit'),
    specifications: const <String>['Pieces: 3 (grout, tile, general-purpose)', 'Ergonomic non-slip handles'],
    relatedProductIds: const <String>['microfiber-mop-set'],
  ),
  ProductDetail(
    preview: _byId['telescopic-squeegee']!,
    sku: 'SKU-TOOL-003',
    description: 'An extendable window squeegee for streak-free cleaning of high or hard-to-reach glass.',
    gallery: _gallery('Telescopic Squeegee'),
    specifications: const <String>['Extends: 60cm–180cm', 'Rubber blade width: 30cm'],
    relatedProductIds: const <String>['microfiber-mop-set', 'glass-cleaner-spray'],
  ),
  ProductDetail(
    preview: _byId['cleaning-caddy-organizer']!,
    sku: 'SKU-ACC-001',
    description: 'A durable carry caddy that keeps cleaning bottles, brushes, and cloths organized room to room.',
    gallery: _gallery('Cleaning Caddy Organizer'),
    specifications: const <String>['Compartments: 4', 'Handle: reinforced carry grip'],
    relatedProductIds: const <String>['microfiber-cloth-pack'],
  ),
  ProductDetail(
    preview: _byId['microfiber-cloth-pack']!,
    sku: 'SKU-ACC-002',
    description: 'A 10-pack of lint-free microfiber cloths for streak-free cleaning on any surface.',
    gallery: _gallery('Microfiber Cloth Pack'),
    specifications: const <String>['Pieces: 10', 'Size: 30cm x 30cm', 'Machine washable'],
    relatedProductIds: const <String>['cleaning-caddy-organizer', 'glass-cleaner-spray'],
  ),
  ProductDetail(
    preview: _byId['disposable-gloves-box']!,
    sku: 'SKU-PPE-001',
    description: 'A box of powder-free disposable gloves suitable for cleaning and general household protection.',
    gallery: _gallery('Disposable Gloves Box'),
    specifications: const <String>['Pieces per box: 100', 'Material: Nitrile', 'Powder-free'],
    priceTiers: const <ProductPriceTier>[ProductPriceTier(quantityLabel: '5+', priceLabel: '4.75 / Box')],
    relatedProductIds: const <String>['n95-protective-masks'],
  ),
  ProductDetail(
    preview: _byId['n95-protective-masks']!,
    sku: 'SKU-PPE-002',
    description: 'A box of N95-rated protective masks for cleaning staff and household use around dust or chemicals.',
    gallery: _gallery('N95 Protective Masks'),
    specifications: const <String>['Pieces per box: 20', 'Rating: N95'],
    allowOptionalQuotation: true,
    relatedProductIds: const <String>['disposable-gloves-box'],
  ),
  ProductDetail(
    preview: _byId['lavender-air-freshener']!,
    sku: 'SKU-AIR-001',
    description: 'A long-lasting lavender air freshener spray that neutralizes odors without an overpowering scent.',
    gallery: _gallery('Lavender Air Freshener'),
    specifications: const <String>['Volume: 300ml', 'Fragrance: Lavender'],
    relatedProductIds: const <String>['citrus-fresh-spray'],
  ),
  ProductDetail(
    preview: _byId['citrus-fresh-spray']!,
    sku: 'SKU-AIR-002',
    description: 'A bright citrus-scented air freshener spray, ideal for kitchens and living areas.',
    gallery: _gallery('Citrus Fresh Spray'),
    specifications: const <String>['Volume: 300ml', 'Fragrance: Citrus'],
    relatedProductIds: const <String>['lavender-air-freshener'],
  ),
];
