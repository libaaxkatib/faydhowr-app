/// Somalia phone number validation — docs/02_SRS.md FR-002A (default
/// country Somalia +252, E.164 format). This is a Foundation-level
/// approximation of the documented rule (E.164, +252 default country); the
/// exact acceptable subscriber-number length is not itself specified in
/// approved documentation, so this should be confirmed against real
/// business rules before Authentication goes live against a real backend.
abstract final class PhoneValidator {
  static const String defaultCountryCode = '+252';

  static final RegExp _somaliaE164 = RegExp(r'^\+252[0-9]{8,9}$');

  /// Returns a user-facing error message, or `null` if valid.
  static String? validate(String? value) {
    final String trimmed = (value ?? '').trim();
    if (trimmed.isEmpty) {
      return 'Phone number is required';
    }
    if (!_somaliaE164.hasMatch(trimmed)) {
      return 'Enter a valid Somalia phone number (e.g. +252 61 234 5678)';
    }
    return null;
  }

  static bool isValid(String value) => validate(value) == null;
}
