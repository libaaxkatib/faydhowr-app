import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../../../../core/error/result.dart';
import 'auth_providers.dart';

part 'login_view_model.g.dart';

/// Login screen ViewModel — docs/09_Flutter_Architecture.md §4 (Riverpod
/// Notifiers as ViewModels). Holds only submission status; the phone
/// number itself is local `TextEditingController` state in the screen
/// (no reason to duplicate it in a provider).
@riverpod
class LoginViewModel extends _$LoginViewModel {
  @override
  FutureOr<void> build() {}

  /// Returns `true` on success (caller navigates to OTP); `false` on
  /// failure (caller reads [state] for the [Failure] to display).
  Future<bool> requestOtp(String phoneNumber) async {
    state = const AsyncLoading<void>();
    final Result<void> result = await ref.read(authenticationRepositoryProvider).requestOtp(phoneNumber);
    switch (result) {
      case Ok<void>():
        state = const AsyncData<void>(null);
        return true;
      case Err<void>(:final failure):
        state = AsyncError<void>(failure, StackTrace.current);
        return false;
    }
  }
}
