import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

/// Bottom-navigation shell — docs/09_Flutter_Architecture.md §7,
/// docs/05_UI_UX_Design.md §6.1: Home · Services · Store · Cart · Account.
///
/// Destinations and order are fixed by approved UX Flow / UI-UX Design —
/// do not add, remove, or reorder tabs here. Tab bodies are Foundation
/// placeholders (Milestone F2) except where a real feature has since
/// replaced one; icons are neutral Material defaults pending a Figma
/// icon-asset pass, not a redesign of the approved structure.
///
/// [cartItemCount] stays a plain `int` (not a Riverpod read) so this widget
/// — living in `core/router/`, which must never depend on `features/`
/// (docs/09_Flutter_Architecture.md §3.1) — has no knowledge of the Store
/// Module's cart provider. `app/app_router.dart` (the composition root,
/// already exempted from that rule) reads the cart count and passes it in.
class AppShellScaffold extends StatelessWidget {
  const AppShellScaffold({required this.navigationShell, this.cartItemCount = 0, super.key});

  final StatefulNavigationShell navigationShell;
  final int cartItemCount;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: navigationShell,
      bottomNavigationBar: NavigationBar(
        selectedIndex: navigationShell.currentIndex,
        onDestinationSelected: (int index) => navigationShell.goBranch(
          index,
          initialLocation: index == navigationShell.currentIndex,
        ),
        destinations: <NavigationDestination>[
          const NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home), label: 'Home'),
          const NavigationDestination(
            icon: Icon(Icons.design_services_outlined),
            selectedIcon: Icon(Icons.design_services),
            label: 'Services',
          ),
          const NavigationDestination(
            icon: Icon(Icons.storefront_outlined),
            selectedIcon: Icon(Icons.storefront),
            label: 'Store',
          ),
          NavigationDestination(
            icon: _CartIcon(count: cartItemCount, filled: false),
            selectedIcon: _CartIcon(count: cartItemCount, filled: true),
            label: 'Cart',
          ),
          const NavigationDestination(icon: Icon(Icons.person_outline), selectedIcon: Icon(Icons.person), label: 'Account'),
        ],
      ),
    );
  }
}

class _CartIcon extends StatelessWidget {
  const _CartIcon({required this.count, required this.filled});

  final int count;
  final bool filled;

  @override
  Widget build(BuildContext context) {
    final Icon icon = filled
        ? const Icon(Icons.shopping_cart)
        : const Icon(Icons.shopping_cart_outlined);
    if (count <= 0) {
      return icon;
    }
    return Badge(label: Text('$count'), child: icon);
  }
}
