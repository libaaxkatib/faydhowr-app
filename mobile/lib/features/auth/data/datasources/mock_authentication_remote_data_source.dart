import 'authentication_remote_data_source.dart';

/// **Mock implementation — Milestone F5 scope.** Simulates network latency
/// and always succeeds; produces a fake token, never contacts any server.
///
/// This must be replaced by a real Dio-backed implementation
/// (`POST /api/v1/auth/phone/request`, `POST /api/v1/auth/phone/verify`
/// per docs/06_API_Design.md §2.2) before Authentication can go live —
/// clearly named and documented so it is never mistaken for production
/// code.
class MockAuthenticationRemoteDataSource implements AuthenticationRemoteDataSource {
  const MockAuthenticationRemoteDataSource();

  @override
  Future<void> requestOtp(String phoneNumber) async {
    await Future<void>.delayed(const Duration(milliseconds: 500));
  }

  @override
  Future<String> verifyOtp({required String phoneNumber, required String otp}) async {
    await Future<void>.delayed(const Duration(milliseconds: 500));
    return 'mock-access-token-$phoneNumber';
  }
}
