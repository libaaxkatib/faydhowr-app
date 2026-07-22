/// Shared local-storage keys. A single source of truth so infrastructure
/// (`core/network/interceptors/auth_interceptor.dart`) and feature code
/// (`features/auth/`) never risk drifting onto different key strings for
/// the same stored value.
abstract final class StorageKeys {
  static const String authAccessToken = 'auth_access_token';
  static const String authPhoneNumber = 'auth_phone_number';
  static const String onboardingCompleted = 'onboarding_completed';
}
