import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/router/app_shell_scaffold.dart';
import '../core/router/foundation_placeholder_screen.dart';
import '../features/auth/presentation/screens/login_screen.dart';
import '../features/auth/presentation/screens/onboarding_screen.dart';
import '../features/auth/presentation/screens/otp_screen.dart';
import '../features/auth/presentation/screens/splash_screen.dart';
import '../features/auth/presentation/screens/welcome_screen.dart';
import '../features/booking/presentation/screens/booking_screen.dart';
import '../features/checkout/domain/entities/checkout_entities.dart';
import '../features/checkout/presentation/screens/checkout_screen.dart';
import '../features/checkout/presentation/screens/order_success_screen.dart';
import '../features/home/presentation/screens/home_screen.dart';
import '../features/orders/presentation/screens/order_details_screen.dart';
import '../features/orders/presentation/screens/orders_list_screen.dart';
import '../features/services/presentation/screens/service_detail_screen.dart';
import '../features/services/presentation/screens/services_list_screen.dart';
import '../features/store/presentation/providers/store_providers.dart';
import '../features/store/presentation/screens/cart_screen.dart';
import '../features/store/presentation/screens/product_details_screen.dart';
import '../features/store/presentation/screens/product_gallery_viewer_screen.dart';
import '../features/store/presentation/screens/store_screen.dart';

/// Builds the app's [GoRouter] — docs/09_Flutter_Architecture.md §7.
///
/// Lives in `app/` (composition root), not `core/router/`: it references
/// concrete feature screens (`features/auth/presentation/screens/*`), and
/// `core` must never import from `features/` (the frozen dependency rule)
/// — but `app/` is explicitly allowed to know about everything.
///
/// **Guest browsing (docs/02_SRS.md — "Value Before Login"):** the app
/// never forces login on launch. [isAuthenticated] (sourced from the real
/// session, `core/router/auth_state_provider.dart`) only gates the routes
/// in [_protectedPathPrefixes] — everything else is guest-accessible.
GoRouter buildAppRouter({required bool Function() isAuthenticated}) {
  return GoRouter(
    initialLocation: '/splash',
    redirect: (BuildContext context, GoRouterState state) {
      final String location = state.matchedLocation;
      final bool isAuthFlowRoute = location == '/login' || location == '/otp';
      if (_isProtectedPath(location) && !isAuthenticated() && !isAuthFlowRoute) {
        // Soft-auth return-to-intent (docs/09_Flutter_Architecture.md
        // §1.2): remember where the guest was headed so a successful
        // login resumes it, rather than dropping them on Home.
        return '/login?redirect=${Uri.encodeComponent(location)}';
      }
      return null;
    },
    routes: <RouteBase>[
      GoRoute(
        path: '/splash',
        builder: (BuildContext context, GoRouterState state) => const SplashScreen(),
      ),
      GoRoute(
        path: '/welcome',
        builder: (BuildContext context, GoRouterState state) => const WelcomeScreen(),
      ),
      GoRoute(
        path: '/onboarding',
        builder: (BuildContext context, GoRouterState state) => const OnboardingScreen(),
      ),
      GoRoute(
        path: '/login',
        builder: (BuildContext context, GoRouterState state) =>
            LoginScreen(redirectTo: state.uri.queryParameters['redirect']),
      ),
      GoRoute(
        path: '/otp',
        builder: (BuildContext context, GoRouterState state) => OtpScreen(
          phoneNumber: state.uri.queryParameters['phone']!,
          redirectTo: state.uri.queryParameters['redirect'],
        ),
      ),
      // Top-level (outside the shell), not nested under the `/services`
      // branch below: a detail page with its own sticky action bar reads
      // better full-screen, without the bottom tab bar competing for
      // space — same reasoning as `/login`/`/otp` being top-level.
      GoRoute(
        path: '/services/:id',
        builder: (BuildContext context, GoRouterState state) =>
            ServiceDetailScreen(serviceId: state.pathParameters['id']!),
      ),
      // Also top-level, same reasoning as `/services/:id`: the Booking
      // form has its own sticky Continue bar and reads better full-screen.
      // Protected by `_protectedPathPrefixes` below — a guest tapping
      // Book Now is redirected to `/login`, then resumes here.
      GoRoute(
        path: '/booking/:serviceId',
        builder: (BuildContext context, GoRouterState state) =>
            BookingScreen(serviceId: state.pathParameters['serviceId']!),
      ),
      // Top-level, same reasoning as `/services/:id`: Product Details has
      // its own sticky Add to Cart / Request Quotation bar.
      GoRoute(
        path: '/store/:id',
        builder: (BuildContext context, GoRouterState state) =>
            ProductDetailsScreen(productId: state.pathParameters['id']!),
      ),
      // Full-screen immersive viewer — no bottom nav, no sticky bar.
      GoRoute(
        path: '/store/:id/gallery',
        builder: (BuildContext context, GoRouterState state) => ProductGalleryViewerScreen(
          productId: state.pathParameters['id']!,
          initialIndex: (state.extra as int?) ?? 0,
        ),
      ),
      // Top-level, auth-gated via `_protectedPathPrefixes` below (Order
      // History is an Account-only, signed-in feature per
      // docs/05_UI_UX_Design.md §3.9).
      GoRoute(
        path: '/orders',
        builder: (BuildContext context, GoRouterState state) => const OrdersListScreen(),
      ),
      GoRoute(
        path: '/orders/:id',
        builder: (BuildContext context, GoRouterState state) =>
            OrderDetailsScreen(orderId: state.pathParameters['id']!),
      ),
      // Top-level, auth-gated (docs/05_UI_UX_Design.md §4.8 — Checkout
      // requires auth even though browsing/cart do not). Store's own Cart
      // Checkout button is frozen and disabled, so this sprint's entry
      // point is the Account "Checkout Demo" button below rather than a
      // change to Store.
      GoRoute(
        path: '/checkout',
        builder: (BuildContext context, GoRouterState state) => const CheckoutScreen(),
      ),
      GoRoute(
        path: '/checkout/success',
        builder: (BuildContext context, GoRouterState state) =>
            OrderSuccessScreen(result: state.extra! as PlacedOrderResult),
      ),
      StatefulShellRoute.indexedStack(
        builder: (BuildContext context, GoRouterState state, StatefulNavigationShell navigationShell) =>
            Consumer(
              builder: (BuildContext context, WidgetRef ref, Widget? child) => AppShellScaffold(
                navigationShell: navigationShell,
                cartItemCount: ref.watch(cartItemCountProvider),
              ),
            ),
        branches: <StatefulShellBranch>[
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: '/home',
                builder: (BuildContext context, GoRouterState state) => const HomeScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: '/services',
                builder: (BuildContext context, GoRouterState state) => const ServicesListScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: '/store',
                builder: (BuildContext context, GoRouterState state) => const StoreScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: '/cart',
                builder: (BuildContext context, GoRouterState state) => const CartScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: '/account',
                // Account itself is still a Foundation placeholder — this
                // adds only the real entry points the Orders and Checkout
                // Modules need (Store is frozen, so neither "Store →
                // Orders" nor Cart's own disabled Checkout button can be
                // the real entry point; Account is Order History's
                // documented home, docs/05_UI_UX_Design.md §3.9, and
                // "Checkout Demo" is this sprint's stand-in entry point
                // since Store's Checkout button stays untouched/disabled).
                // ORDERS MODULE — FROZEN ✅ / CHECKOUT MODULE — FROZEN ✅:
                // these entry points are part of those freezes — no
                // further UI/UX changes without an explicit new request.
                builder: (BuildContext context, GoRouterState state) => FoundationPlaceholderScreen(
                  label: 'Account',
                  action: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      FilledButton(
                        onPressed: () => context.push('/orders'),
                        child: const Text('My Orders'),
                      ),
                      const SizedBox(height: 12),
                      OutlinedButton(
                        onPressed: () => context.push('/checkout'),
                        child: const Text('Checkout Demo'),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    ],
  );
}

/// Routes that require authentication. `/quotation` does not exist yet
/// (a later module) — pre-registered here so the guard requires no
/// changes when it lands; `/account`, `/booking`, `/checkout`, and
/// `/orders` are all real routes today.
const Set<String> _protectedPathPrefixes = <String>{
  '/account',
  '/booking',
  '/quotation',
  '/checkout',
  '/orders',
};

bool _isProtectedPath(String path) {
  return _protectedPathPrefixes.any(
    (String prefix) => path == prefix || path.startsWith('$prefix/'),
  );
}
