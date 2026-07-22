import 'package:fayadhowr/features/auth/domain/validators/phone_validator.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('PhoneValidator', () {
    test('rejects empty input', () {
      expect(PhoneValidator.validate(''), isNotNull);
      expect(PhoneValidator.validate(null), isNotNull);
      expect(PhoneValidator.validate('   '), isNotNull);
    });

    test('rejects numbers without the Somalia country code', () {
      expect(PhoneValidator.validate('0612345678'), isNotNull);
      expect(PhoneValidator.validate('+1 555 123 4567'), isNotNull);
    });

    test('rejects malformed Somalia numbers', () {
      expect(PhoneValidator.validate('+252123'), isNotNull); // too short
      expect(PhoneValidator.validate('+252abcdefgh'), isNotNull); // non-digits
    });

    test('accepts a valid Somalia E.164 number', () {
      expect(PhoneValidator.validate('+25261234567'), isNull);
      expect(PhoneValidator.isValid('+25261234567'), isTrue);
    });
  });
}
