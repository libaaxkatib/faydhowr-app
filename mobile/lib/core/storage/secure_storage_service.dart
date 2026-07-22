import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Generic secure storage abstraction — docs/09_Flutter_Architecture.md §8.
///
/// Infrastructure only: no business keys, no session/token logic. Feature
/// code (e.g. the future Auth feature) owns its own key names and calls
/// through this abstraction rather than touching [FlutterSecureStorage]
/// directly.
class SecureStorageService {
  const SecureStorageService(this._storage);

  final FlutterSecureStorage _storage;

  Future<String?> read(String key) => _storage.read(key: key);

  Future<void> write(String key, String value) => _storage.write(key: key, value: value);

  Future<void> delete(String key) => _storage.delete(key: key);

  Future<void> deleteAll() => _storage.deleteAll();
}
