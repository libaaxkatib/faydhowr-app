import '../../../../core/session/auth_session.dart';

/// Local session-persistence contract — docs/09_Flutter_Architecture.md
/// §8, §5. See `secure_authentication_local_data_source.dart` for the real
/// implementation, backed by the existing `SecureStorageService`
/// (Milestone F2 infrastructure) — this is local device storage, not a
/// remote API, so a genuine implementation is in scope here.
abstract interface class AuthenticationLocalDataSource {
  Future<void> saveSession(AuthSession session);

  Future<AuthSession?> readSession();

  Future<void> clearSession();
}
