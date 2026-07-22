import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth_session.dart';

/// The app-wide current session, if any. The Authentication feature writes
/// to this on login/logout via [update]; `core/router/auth_state_provider.dart`
/// reads it to drive the redirect guard. Neither side depends on the other
/// — both depend only on this core provider.
class SessionNotifier extends Notifier<AuthSession?> {
  @override
  AuthSession? build() => null;

  void update(AuthSession? session) => state = session;
}

final sessionProvider = NotifierProvider<SessionNotifier, AuthSession?>(SessionNotifier.new);
