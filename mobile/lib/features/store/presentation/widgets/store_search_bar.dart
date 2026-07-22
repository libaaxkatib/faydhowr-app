import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../providers/store_providers.dart';

/// Store Catalog search bar — same premium shadow/rounded-corner treatment
/// as Home's and the Services Module's search bars, wired to this module's
/// own query provider.
///
/// Part of STORE HERO — FROZEN ✅ (see `StoreHeroSection`): this widget
/// floats over the hero, and its slightly stronger shadow (alpha 0.18,
/// blur 18, offset (0, 6) — a touch more than Home/Services' 0.08/16/4) is
/// approved final tuning for separating it from the hero image/gradient
/// behind it, not an inconsistency to "fix" later.
class StoreSearchBar extends ConsumerStatefulWidget {
  const StoreSearchBar({super.key});

  @override
  ConsumerState<StoreSearchBar> createState() => _StoreSearchBarState();
}

class _StoreSearchBarState extends ConsumerState<StoreSearchBar> {
  final TextEditingController _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _clear() {
    _controller.clear();
    ref.read(storeSearchQueryProvider.notifier).update('');
  }

  @override
  Widget build(BuildContext context) {
    final bool hasText = ref.watch(storeSearchQueryProvider).isNotEmpty;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          boxShadow: <BoxShadow>[
            BoxShadow(
              color: AppColors.primary.withValues(alpha: 0.18),
              blurRadius: 18,
              offset: const Offset(0, 6),
            ),
          ],
        ),
        child: Semantics(
          textField: true,
          label: 'Search products',
          child: TextField(
            controller: _controller,
            onChanged: (String value) => ref.read(storeSearchQueryProvider.notifier).update(value),
            decoration: InputDecoration(
              hintText: 'Search products',
              hintStyle: AppTypography.body.copyWith(color: AppColors.textSecondary),
              filled: true,
              fillColor: AppColors.white,
              prefixIcon: const Icon(Icons.search, color: AppColors.primary),
              suffixIcon: hasText
                  ? Semantics(
                      button: true,
                      label: 'Clear search',
                      child: IconButton(
                        icon: const Icon(Icons.close, color: AppColors.textSecondary),
                        onPressed: _clear,
                      ),
                    )
                  : null,
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(AppRadius.lg),
                borderSide: BorderSide.none,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
