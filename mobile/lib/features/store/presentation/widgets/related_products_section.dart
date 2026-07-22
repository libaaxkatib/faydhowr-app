import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_spacing.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../domain/entities/store_entities.dart';
import '../providers/store_providers.dart';
import 'product_card.dart';

/// Related Products — horizontal strip of [ProductCard]s linking to other
/// products, same idiom as Services' `ServiceRelatedSection`. Navigates via
/// `push`, same as the Store Catalog grid.
class RelatedProductsSection extends ConsumerWidget {
  const RelatedProductsSection({required this.relatedProductIds, super.key});

  final List<String> relatedProductIds;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (relatedProductIds.isEmpty) {
      return const SizedBox.shrink();
    }
    final Map<String, ProductPreview> byId = <String, ProductPreview>{
      for (final ProductPreview p in ref.watch(productCatalogProvider)) p.id: p,
    };
    final List<ProductPreview> related = <ProductPreview>[
      for (final String id in relatedProductIds)
        if (byId.containsKey(id)) byId[id]!,
    ];
    if (related.isEmpty) {
      return const SizedBox.shrink();
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SectionHeader(title: 'Related Products'),
        SizedBox(
          height: 260,
          child: ListView.builder(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
            itemCount: related.length,
            itemBuilder: (BuildContext context, int index) {
              final ProductPreview product = related[index];
              return Padding(
                padding: const EdgeInsets.only(right: AppSpacing.space3),
                child: SizedBox(
                  width: 160,
                  child: ProductCard(product: product, onTap: () => context.push('/store/${product.id}')),
                ),
              );
            },
          ),
        ),
      ],
    );
  }
}
