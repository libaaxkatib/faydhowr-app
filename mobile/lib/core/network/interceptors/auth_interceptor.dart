import 'package:dio/dio.dart';

import '../../storage/secure_storage_service.dart';
import '../../storage/storage_keys.dart';

/// Attaches a Bearer token when one exists in secure storage —
/// docs/09_Flutter_Architecture.md §6.3/§6.4, interceptor stack position 2.
///
/// The Authentication feature (`features/auth/`) writes to
/// [StorageKeys.authAccessToken] on successful login; this interceptor
/// reads the same key, so the two stay connected without either depending
/// on the other directly.
class AuthInterceptor extends Interceptor {
  AuthInterceptor(this._secureStorage);

  final SecureStorageService _secureStorage;

  @override
  Future<void> onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    final String? token = await _secureStorage.read(StorageKeys.authAccessToken);
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }
}
