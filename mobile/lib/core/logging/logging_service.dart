import 'package:flutter/foundation.dart';

/// Reusable logging abstraction — docs/09_Flutter_Architecture.md §20/§6.3.
///
/// No analytics, no crash reporting (explicitly out of scope for Milestone
/// F3): output is console-only via [debugPrint], gated by build mode and
/// the environment's debug flag. `error()` is the integration point a
/// future crash reporter (Sentry/Crashlytics, per §18) would hook into —
/// deliberately left as a no-op sink beyond console output for now.
abstract class LoggingService {
  void debug(String message, {String? tag});

  void error(String message, {Object? error, StackTrace? stackTrace, String? tag});
}

class AppLoggingService implements LoggingService {
  const AppLoggingService({required this.verbose});

  /// Enables debug-level output. Always false in production
  /// (`Environment.production.config.isDebug == false`).
  final bool verbose;

  @override
  void debug(String message, {String? tag}) {
    if (!verbose || !kDebugMode) {
      return;
    }
    debugPrint('[${tag ?? 'debug'}] $message');
  }

  @override
  void error(String message, {Object? error, StackTrace? stackTrace, String? tag}) {
    if (!kDebugMode) {
      return;
    }
    debugPrint('[${tag ?? 'error'}] $message${error != null ? ' — $error' : ''}');
    if (stackTrace != null) {
      debugPrint(stackTrace.toString());
    }
  }
}
