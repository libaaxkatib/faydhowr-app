/// Remote data source contract — docs/09_Flutter_Architecture.md §1,
/// §2 layering. Interface only, per Milestone F5 scope: no real backend
/// integration exists. See `mock_authentication_remote_data_source.dart`
/// for the mocked implementation used until real API integration lands
/// (docs/06_API_Design.md §2.2 — phone/request, phone/verify).
abstract interface class AuthenticationRemoteDataSource {
  Future<void> requestOtp(String phoneNumber);

  /// Returns the access token on success. Throws on failure — the
  /// repository maps thrown exceptions to [Failure] (see
  /// `data/repositories/authentication_repository_impl.dart`).
  Future<String> verifyOtp({required String phoneNumber, required String otp});
}
