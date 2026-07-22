import '../../../../core/error/result.dart';
import '../../../../core/session/auth_session.dart';

/// Domain contract for authentication — docs/09_Flutter_Architecture.md §5
/// Repository pattern ("Interface provider → impl provider, overridable in
/// tests"). Interface only, per Milestone F5 scope; see
/// `data/repositories/authentication_repository_impl.dart` for the
/// implementation, which composes a mock remote data source (no real
/// backend yet) with the real local storage infrastructure.
abstract interface class AuthenticationRepository {
  /// Reads any previously-persisted session (e.g. after app restart).
  /// Does not contact the network.
  Future<AuthSession?> restoreSession();

  Future<Result<void>> requestOtp(String phoneNumber);

  Future<Result<AuthSession>> verifyOtp({required String phoneNumber, required String otp});

  Future<void> logout();
}
