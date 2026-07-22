import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/lifecycle/app_lifecycle_provider.dart';
import '../core/router/auth_state_provider.dart';
import '../core/theme/app_theme.dart';
import '../l10n/app_localizations.dart';
import 'app_router.dart';

/// Router provider — lives here (not `core/di/root_providers.dart`) since
/// Milestone F5, because `buildAppRouter()` now references concrete
/// feature screens; see `app_router.dart` for the full explanation.
final appRouterProvider = Provider<GoRouter>(
  (ref) => buildAppRouter(isAuthenticated: () => ref.read(isAuthenticatedProvider)),
);

/// Foundation + Authentication bootstrap shell (Milestone F5).
///
/// Wires theme, GoRouter, and localization through Riverpod.
class FayadhowrApp extends ConsumerWidget {
  const FayadhowrApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final GoRouter router = ref.watch(appRouterProvider);
    // Keeps the lifecycle observer registered for the app's lifetime.
    ref.watch(appLifecycleProvider);

    return MaterialApp.router(
      title: 'Fayadhowr',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light,
      routerConfig: router,
      locale: const Locale('en'),
      supportedLocales: AppLocalizations.supportedLocales,
      localizationsDelegates: const <LocalizationsDelegate<dynamic>>[
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
    );
  }
}
