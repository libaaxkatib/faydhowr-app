import '../../../../core/session/auth_session.dart';
import '../../../../core/storage/secure_storage_service.dart';
import '../../../../core/storage/storage_keys.dart';
import 'authentication_local_data_source.dart';

/// Real implementation backed by the existing `SecureStorageService`
/// (Milestone F2). Writes to [StorageKeys.authAccessToken] — the same key
/// `core/network/interceptors/auth_interceptor.dart` reads — so a
/// successful login is immediately usable by the networking layer with no
/// further wiring.
class SecureAuthenticationLocalDataSource implements AuthenticationLocalDataSource {
  SecureAuthenticationLocalDataSource(this._secureStorage);

  final SecureStorageService _secureStorage;

  @override
  Future<void> saveSession(AuthSession session) async {
    await _secureStorage.write(StorageKeys.authAccessToken, session.accessToken);
    await _secureStorage.write(StorageKeys.authPhoneNumber, session.phoneNumber);
  }

  @override
  Future<AuthSession?> readSession() async {
    final String? token = await _secureStorage.read(StorageKeys.authAccessToken);
    final String? phoneNumber = await _secureStorage.read(StorageKeys.authPhoneNumber);
    if (token == null || token.isEmpty || phoneNumber == null || phoneNumber.isEmpty) {
      return null;
    }
    return AuthSession(phoneNumber: phoneNumber, accessToken: token);
  }

  @override
  Future<void> clearSession() async {
    await _secureStorage.delete(StorageKeys.authAccessToken);
    await _secureStorage.delete(StorageKeys.authPhoneNumber);
  }
}
