/// Application configuration — docs/09_Flutter_Architecture.md §17.
///
/// Per-environment instances are provided by [Environment.config]
/// (`core/config/environment.dart`), selected at build time by which
/// flavored entry point runs. `baseUrl` values remain placeholders — no
/// real per-environment API endpoint exists yet (Milestone F3 scope).
class AppConfig {
  const AppConfig({
    required this.appName,
    required this.baseUrl,
    required this.enableNetworkLogging,
    required this.isDebug,
    this.connectTimeout = const Duration(seconds: 10),
    this.receiveTimeout = const Duration(seconds: 20),
    this.sendTimeout = const Duration(seconds: 20),
  });

  final String appName;
  final String baseUrl;
  final Duration connectTimeout;
  final Duration receiveTimeout;
  final Duration sendTimeout;

  /// Debug/staging only per docs/09_Flutter_Architecture.md §6.3 and the
  /// Architecture Review Logging expansion — must be false in release builds.
  final bool enableNetworkLogging;

  /// Drives verbose logging and other debug-only behavior — distinct from
  /// Flutter's own `kDebugMode`/`kReleaseMode` build mode, since staging
  /// builds run in Flutter "release" mode but may still want relaxed
  /// logging. See `core/logging/logging_service.dart`.
  final bool isDebug;
}
