import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Reusable app-lifecycle observer — docs/09_Flutter_Architecture.md §19.
///
/// Exposes the current [AppLifecycleState] (resumed/inactive/paused/
/// detached/hidden) for other code to watch. No business actions are
/// triggered here — this is observation infrastructure only; features
/// (e.g. pausing polling, re-checking session on resume) subscribe to this
/// provider and decide their own behavior later.
class AppLifecycleNotifier extends Notifier<AppLifecycleState> with WidgetsBindingObserver {
  @override
  AppLifecycleState build() {
    WidgetsBinding.instance.addObserver(this);
    ref.onDispose(() => WidgetsBinding.instance.removeObserver(this));
    return WidgetsBinding.instance.lifecycleState ?? AppLifecycleState.resumed;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    this.state = state;
  }
}

final appLifecycleProvider = NotifierProvider<AppLifecycleNotifier, AppLifecycleState>(
  AppLifecycleNotifier.new,
);
