import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../core/config/environment.dart';
import '../core/di/root_providers.dart';
import '../core/error/production_error_screen.dart';
import '../core/logging/logging_service.dart';
import 'app.dart';

/// Shared Foundation bootstrap for every flavor — docs/09_Flutter_Architecture.md
/// §17, §3 (`app/bootstrap.dart`: "flavor init, DI, error zone, storage").
///
/// Implements Milestone F3 Global Error Handling: `FlutterError.onError`,
/// `PlatformDispatcher.instance.onError`, and a zone-level uncaught
/// exception guard, all forwarding to [LoggingService]. No crash reporter
/// is wired (out of scope) — `LoggingService.error()` is the integration
/// point for one later.
Future<void> bootstrap(Environment environment) async {
  final AppLoggingService logging = AppLoggingService(verbose: environment.config.isDebug);

  runZonedGuarded(
    () async {
      WidgetsFlutterBinding.ensureInitialized();

      FlutterError.onError = (FlutterErrorDetails details) {
        logging.error(
          details.exceptionAsString(),
          error: details.exception,
          stackTrace: details.stack,
          tag: 'FlutterError',
        );
        FlutterError.presentError(details);
      };

      PlatformDispatcher.instance.onError = (Object error, StackTrace stack) {
        logging.error(error.toString(), error: error, stackTrace: stack, tag: 'PlatformDispatcher');
        return true;
      };

      if (kReleaseMode) {
        ErrorWidget.builder = (FlutterErrorDetails details) => const ProductionErrorScreen();
      }

      final SharedPreferences sharedPreferences = await SharedPreferences.getInstance();

      runApp(
        ProviderScope(
          overrides: [
            environmentProvider.overrideWithValue(environment),
            sharedPreferencesProvider.overrideWithValue(sharedPreferences),
          ],
          child: const FayadhowrApp(),
        ),
      );
    },
    (Object error, StackTrace stack) {
      logging.error(error.toString(), error: error, stackTrace: stack, tag: 'Zone');
    },
  );
}
