import 'package:flutter/material.dart';

import '../../../../core/theme/app_spacing.dart';
import '../../domain/entities/home_preview_entities.dart';
import 'home_section_header.dart';
import 'store_product_card.dart';

/// Store Products: horizontally scrollable product cards.
class StorePreviewSection extends StatelessWidget {
  const StorePreviewSection({required this.products, super.key});

  final List<StoreProductPreview> products;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const HomeSectionHeader(title: 'Store Products'),
        SizedBox(
          height: 220,
          child: ListView.builder(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
            itemCount: products.length,
            itemBuilder: (BuildContext context, int index) => Padding(
              padding: const EdgeInsets.only(right: AppSpacing.space3),
              child: StoreProductCard(product: products[index]),
            ),
          ),
        ),
      ],
    );
  }
}
