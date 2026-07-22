import 'app/bootstrap.dart';
import 'core/config/environment.dart';

/// Default development entry point — delegates to the same `bootstrap()`
/// used by `main_dev.dart`/`main_staging.dart`/`main_prod.dart` (single
/// source of truth, no duplicated bootstrap or config logic). Exists so
/// plain `flutter run` (no `-t`/`--flavor` flags) works as a Development
/// convenience alongside the three flavored entry points, which remain
/// unchanged.
void main() => bootstrap(Environment.dev);
