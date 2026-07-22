import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/di/root_providers.dart';
import '../../../../core/storage/storage_keys.dart';

/// Whether onboarding has previously been completed on this device —
/// reuses the existing `PreferencesService` (Milestone F2 infrastructure)
/// rather than introducing a new storage abstraction for a single flag.
final hasCompletedOnboardingProvider = Provider<bool>(
  (ref) => ref.watch(preferencesServiceProvider).getBool(StorageKeys.onboardingCompleted) ?? false,
);

Future<void> markOnboardingCompleted(WidgetRef ref) async {
  await ref.read(preferencesServiceProvider).setBool(StorageKeys.onboardingCompleted, value: true);
  ref.invalidate(hasCompletedOnboardingProvider);
}
