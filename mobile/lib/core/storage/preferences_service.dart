import 'package:shared_preferences/shared_preferences.dart';

/// Generic shared-preferences abstraction — docs/09_Flutter_Architecture.md §8.
///
/// Infrastructure only: no business keys, no feature usage yet (locale,
/// theme mode, onboarding flags, recent searches are all future consumers).
class PreferencesService {
  const PreferencesService(this._preferences);

  final SharedPreferences _preferences;

  String? getString(String key) => _preferences.getString(key);

  Future<bool> setString(String key, String value) => _preferences.setString(key, value);

  bool? getBool(String key) => _preferences.getBool(key);

  Future<bool> setBool(String key, {required bool value}) => _preferences.setBool(key, value);

  Future<bool> remove(String key) => _preferences.remove(key);
}
