import 'app_config.dart';

/// Build-time environment — docs/09_Flutter_Architecture.md §17.
///
/// Selected by which flavored entry point runs (`main_dev.dart`,
/// `main_staging.dart`, `main_prod.dart`), paired with the matching native
/// Android product flavor. Base URLs below are placeholders — no real API
/// endpoint exists per-environment yet (Milestone F3 scope).
enum Environment {
  dev,
  staging,
  production;

  AppConfig get config => switch (this) {
        Environment.dev => const AppConfig(
            appName: 'Fayadhowr Dev',
            baseUrl: 'https://dev-api.fayadhowr.example/api/v1',
            enableNetworkLogging: true,
            isDebug: true,
          ),
        Environment.staging => const AppConfig(
            appName: 'Fayadhowr Staging',
            baseUrl: 'https://staging-api.fayadhowr.example/api/v1',
            enableNetworkLogging: true,
            isDebug: false,
          ),
        Environment.production => const AppConfig(
            appName: 'Fayadhowr',
            baseUrl: 'https://api.fayadhowr.example/api/v1',
            enableNetworkLogging: false,
            isDebug: false,
          ),
      };
}
