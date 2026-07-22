# Fayadhowr — Flutter App: Local Development

This is a developer-workflow guide only. For architecture, see `docs/09_Flutter_Architecture.md` (frozen); for project governance, see `docs/00_AI_PROJECT_CONTEXT.md`. Nothing here changes architecture, `AppConfig`, `Environment`, or any runtime behavior — it documents how to run what already exists.

## Running a Flavor

Three build flavors exist, each paired with its own Dart entry point:

| Flavor | Command | Applies |
| --- | --- | --- |
| **Development** | `flutter run --flavor dev -t lib/main_dev.dart` | `Environment.dev` — verbose logging, dev placeholder base URL |
| **Staging** | `flutter run --flavor staging -t lib/main_staging.dart` | `Environment.staging` |
| **Production** | `flutter run --flavor production -t lib/main_prod.dart` | `Environment.production` — verbose logging off |

**Local development default:** `lib/main.dart` also exists as a plain delegate to `bootstrap(Environment.dev)`, so bare `flutter run` (no flags) launches with the Development *Dart configuration* by default. Note the one nuance already flagged in the F3 follow-up: bare `flutter run` does not automatically select the native Android `dev` *flavor* (that still requires `--flavor dev` explicitly) — it only guarantees the Development `AppConfig`/`Environment` is what boots. For a build that is correct at both the Dart and native-Android level, use the full command:

```bash
flutter run --flavor dev -t lib/main_dev.dart
```

## Recommended Local Default

For day-to-day development, run:

```bash
flutter run --flavor dev -t lib/main_dev.dart
```

This is the combination that is correct at both the Dart (`Environment.dev`) and native Android (`applicationIdSuffix = ".dev"`) level.

## Building Each Flavor

```bash
flutter build apk --flavor dev -t lib/main_dev.dart
flutter build apk --flavor staging -t lib/main_staging.dart
flutter build apk --flavor production -t lib/main_prod.dart
```

All three were verified to build successfully as part of Milestone F3.

## VS Code

No `.vscode/launch.json` currently exists in this repository. If your team wants one, here is a configuration that matches the recommended default above — add it as `.vscode/launch.json` if/when the team decides to adopt one (not created automatically by this guide):

```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Fayadhowr (dev)",
      "request": "launch",
      "type": "dart",
      "program": "lib/main_dev.dart",
      "args": ["--flavor", "dev"]
    },
    {
      "name": "Fayadhowr (staging)",
      "request": "launch",
      "type": "dart",
      "program": "lib/main_staging.dart",
      "args": ["--flavor", "staging"]
    },
    {
      "name": "Fayadhowr (production)",
      "request": "launch",
      "type": "dart",
      "program": "lib/main_prod.dart",
      "args": ["--flavor", "production"]
    }
  ]
}
```

## Android Studio / IntelliJ

A Flutter run configuration already exists in this repository at `.idea/runConfigurations/main_dart.xml` (pointing at `lib/main.dart`) and has been updated to pass `--flavor dev` by default, so the existing "main.dart" run configuration in Android Studio now launches the Development flavor correctly out of the box.

If you want dedicated configurations for staging/production instead of switching the args on the existing one, create them via **Run → Edit Configurations → + → Flutter**, using:

| Name | Dart entrypoint | Additional run args |
| --- | --- | --- |
| dev | `lib/main_dev.dart` | `--flavor dev` |
| staging | `lib/main_staging.dart` | `--flavor staging` |
| production | `lib/main_prod.dart` | `--flavor production` |

These are not created automatically — Android Studio run configurations are local developer preference, and per project convention we don't generate new IDE-specific files unless they're already part of the repository.

## iOS

Flavor-specific Xcode schemes have **not** been created for iOS yet — this requires macOS/Xcode, which wasn't available during Milestones F1–F3. `lib/main_dev.dart` / `main_staging.dart` / `main_prod.dart` are already cross-platform and ready for whenever iOS scheme setup happens.
