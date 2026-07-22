import 'package:dio/dio.dart';

import '../config/app_config.dart';
import '../logging/logging_service.dart';
import 'interceptors/auth_interceptor.dart';
import 'interceptors/error_interceptor.dart';
import 'interceptors/header_interceptor.dart';
import 'interceptors/logging_interceptor.dart';

/// Builds the shared [Dio] client — docs/09_Flutter_Architecture.md §6.
///
/// Interceptor order matches §6.3: Headers → Auth → Error → Logging.
/// Retry is intentionally not implemented in Milestone F2/F3 (out of
/// scope; no endpoint calls exist yet to retry).
Dio buildDioClient({
  required AppConfig config,
  required AuthInterceptor authInterceptor,
  required LoggingService logging,
}) {
  final Dio dio = Dio(
    BaseOptions(
      baseUrl: config.baseUrl,
      connectTimeout: config.connectTimeout,
      receiveTimeout: config.receiveTimeout,
      sendTimeout: config.sendTimeout,
    ),
  );

  dio.interceptors.addAll(<Interceptor>[
    HeaderInterceptor(),
    authInterceptor,
    ErrorInterceptor(logging),
    LoggingInterceptor(logging),
  ]);

  return dio;
}
