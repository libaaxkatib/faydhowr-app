import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../config/app_config.dart';
import '../config/environment.dart';
import '../logging/logging_service.dart';
import '../network/dio_client.dart';
import '../network/interceptors/auth_interceptor.dart';
import '../storage/preferences_service.dart';
import '../storage/secure_storage_service.dart';

/// Root Riverpod provider wiring — docs/09_Flutter_Architecture.md §5.
///
/// Configuration, Networking, Storage, and Logging are bound here.
/// `appRouterProvider` moved to `app/app.dart` as of Milestone F5, since
/// building the router now requires referencing concrete feature screens
/// (`core` must never import `features/`) — see `app/app_router.dart` for
/// the full explanation. Lifecycle (`core/lifecycle/`) and Connectivity
/// (`core/connectivity/`) providers are self-contained and live in their
/// own files rather than being re-declared here.

/// Overridden in each flavored entry point (`main_dev.dart` etc.) via
/// `bootstrap()`. No default value is provided deliberately — reading it
/// without an override is a programming error, not a silent default.
final environmentProvider = Provider<Environment>(
  (ref) => throw UnimplementedError(
    'environmentProvider must be overridden by bootstrap() with the '
    'flavor-selected Environment.',
  ),
);

final appConfigProvider = Provider<AppConfig>((ref) => ref.watch(environmentProvider).config);

final loggingServiceProvider = Provider<LoggingService>(
  (ref) => AppLoggingService(verbose: ref.watch(appConfigProvider).isDebug),
);

// --- Storage -----------------------------------------------------------

final flutterSecureStorageProvider = Provider<FlutterSecureStorage>(
  (ref) => const FlutterSecureStorage(),
);

final secureStorageServiceProvider = Provider<SecureStorageService>(
  (ref) => SecureStorageService(ref.watch(flutterSecureStorageProvider)),
);

/// Overridden in `bootstrap()` with the resolved [SharedPreferences]
/// instance obtained during app bootstrap (async init must complete before
/// `runApp`).
final sharedPreferencesProvider = Provider<SharedPreferences>(
  (ref) => throw UnimplementedError(
    'sharedPreferencesProvider must be overridden by bootstrap() after '
    'SharedPreferences.getInstance() completes.',
  ),
);

final preferencesServiceProvider = Provider<PreferencesService>(
  (ref) => PreferencesService(ref.watch(sharedPreferencesProvider)),
);

// --- Networking ----------------------------------------------------------

final authInterceptorProvider = Provider<AuthInterceptor>(
  (ref) => AuthInterceptor(ref.watch(secureStorageServiceProvider)),
);

final dioProvider = Provider<Dio>(
  (ref) => buildDioClient(
    config: ref.watch(appConfigProvider),
    authInterceptor: ref.watch(authInterceptorProvider),
    logging: ref.watch(loggingServiceProvider),
  ),
);
