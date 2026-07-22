import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_colors.dart';
import '../providers/splash_controller.dart';

/// Splash Screen: displays the official full-screen Fayadhowr splash
/// artwork, restores any session, holds briefly, then always continues to
/// Welcome (Authentication UX Revision). [restoreSessionOnLaunch] is the
/// only business logic here and is unchanged — everything added in this
/// widget is purely the entrance/exit animation timing around it.
class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key});

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen> with SingleTickerProviderStateMixin {
  static const String _splashAsset = 'assets/images/splash use this image.png';

  static const Duration _entranceDuration = Duration(milliseconds: 350);
  static const Duration _holdDuration = Duration(seconds: 2);
  static const Duration _exitDuration = Duration(milliseconds: 300);

  late final AnimationController _entranceController;
  late final Animation<double> _fadeIn;
  late final Animation<double> _scaleIn;
  bool _exiting = false;

  @override
  void initState() {
    super.initState();
    _entranceController = AnimationController(vsync: this, duration: _entranceDuration);
    final CurvedAnimation curved = CurvedAnimation(parent: _entranceController, curve: Curves.easeOut);
    _fadeIn = Tween<double>(begin: 0, end: 1).animate(curved);
    _scaleIn = Tween<double>(begin: 0.95, end: 1).animate(curved);
    _entranceController.forward();
    WidgetsBinding.instance.addPostFrameCallback((_) => _initialize());
  }

  Future<void> _initialize() async {
    // Session restore (business logic, unchanged) runs alongside the
    // premium ~2s hold — whichever takes longer determines when we
    // continue, so a slow restore never gets cut short by the timer.
    await Future.wait(<Future<void>>[
      restoreSessionOnLaunch(ref),
      Future<void>.delayed(_holdDuration),
    ]);
    if (!mounted) {
      return;
    }
    setState(() => _exiting = true);
    await Future<void>.delayed(_exitDuration);
    if (!mounted) {
      return;
    }
    context.go('/welcome');
  }

  @override
  void dispose() {
    _entranceController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      // Fallback only, visible for at most one frame while the image
      // decodes — the artwork itself fills the entire screen via
      // BoxFit.cover, so this color is never actually seen in practice.
      backgroundColor: AppColors.primary,
      body: AnimatedOpacity(
        duration: _exitDuration,
        opacity: _exiting ? 0 : 1,
        child: AnimatedBuilder(
          animation: _entranceController,
          builder: (BuildContext context, Widget? child) {
            return Opacity(
              opacity: _fadeIn.value,
              child: Transform.scale(scale: _scaleIn.value, child: child),
            );
          },
          child: SizedBox.expand(
            child: Image.asset(_splashAsset, fit: BoxFit.cover),
          ),
        ),
      ),
    );
  }
}
