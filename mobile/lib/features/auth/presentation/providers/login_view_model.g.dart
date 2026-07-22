// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'login_view_model.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning
/// Login screen ViewModel — docs/09_Flutter_Architecture.md §4 (Riverpod
/// Notifiers as ViewModels). Holds only submission status; the phone
/// number itself is local `TextEditingController` state in the screen
/// (no reason to duplicate it in a provider).

@ProviderFor(LoginViewModel)
final loginViewModelProvider = LoginViewModelProvider._();

/// Login screen ViewModel — docs/09_Flutter_Architecture.md §4 (Riverpod
/// Notifiers as ViewModels). Holds only submission status; the phone
/// number itself is local `TextEditingController` state in the screen
/// (no reason to duplicate it in a provider).
final class LoginViewModelProvider
    extends $AsyncNotifierProvider<LoginViewModel, void> {
  /// Login screen ViewModel — docs/09_Flutter_Architecture.md §4 (Riverpod
  /// Notifiers as ViewModels). Holds only submission status; the phone
  /// number itself is local `TextEditingController` state in the screen
  /// (no reason to duplicate it in a provider).
  LoginViewModelProvider._()
    : super(
        from: null,
        argument: null,
        retry: null,
        name: r'loginViewModelProvider',
        isAutoDispose: true,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$loginViewModelHash();

  @$internal
  @override
  LoginViewModel create() => LoginViewModel();
}

String _$loginViewModelHash() => r'7473cecd6d243961ce895e898207a105ee3ee6b3';

/// Login screen ViewModel — docs/09_Flutter_Architecture.md §4 (Riverpod
/// Notifiers as ViewModels). Holds only submission status; the phone
/// number itself is local `TextEditingController` state in the screen
/// (no reason to duplicate it in a provider).

abstract class _$LoginViewModel extends $AsyncNotifier<void> {
  FutureOr<void> build();
  @$mustCallSuper
  @override
  WhenComplete runBuild() {
    final ref = this.ref as $Ref<AsyncValue<void>, void>;
    final element =
        ref.element
            as $ClassProviderElement<
              AnyNotifier<AsyncValue<void>, void>,
              AsyncValue<void>,
              Object?,
              Object?
            >;
    return element.handleCreate(ref, build);
  }
}
