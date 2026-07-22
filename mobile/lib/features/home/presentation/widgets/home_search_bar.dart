import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../providers/home_providers.dart';

/// Search bar (Milestone F6 task 2). No real searching — text is held in
/// [homeSearchQueryProvider] so a future Search module can consume it
/// without touching this widget.
class HomeSearchBar extends ConsumerStatefulWidget {
  const HomeSearchBar({super.key});

  @override
  ConsumerState<HomeSearchBar> createState() => _HomeSearchBarState();
}

class _HomeSearchBarState extends ConsumerState<HomeSearchBar> {
  final TextEditingController _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _clear() {
    _controller.clear();
    ref.read(homeSearchQueryProvider.notifier).update('');
  }

  @override
  Widget build(BuildContext context) {
    final bool hasText = ref.watch(homeSearchQueryProvider).isNotEmpty;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          boxShadow: <BoxShadow>[
            BoxShadow(
              color: AppColors.primary.withValues(alpha: 0.08),
              blurRadius: 16,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: Semantics(
          textField: true,
          label: 'Search services and products',
          child: TextField(
            controller: _controller,
            onChanged: (String value) => ref.read(homeSearchQueryProvider.notifier).update(value),
            decoration: InputDecoration(
              hintText: 'Search services and products',
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
