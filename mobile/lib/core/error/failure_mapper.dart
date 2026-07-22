import 'package:dio/dio.dart';

import 'failure.dart';

/// Maps a [DioException] to a typed [Failure], following the HTTP
/// status/error_code taxonomy in docs/06_API_Design.md §3.5 and the mapping
/// table in docs/09_Flutter_Architecture.md §11.
///
/// This is a pure mapping utility, not a Dio interceptor — error mapping
/// belongs to the layer that calls the network (future repositories), so
/// Dio's own exception types remain intact through the interceptor chain.
abstract final class FailureMapper {
  static Failure fromDioException(DioException exception) {
    switch (exception.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      case DioExceptionType.transformTimeout:
        return const TimeoutFailure();
      case DioExceptionType.connectionError:
        return const NetworkFailure();
      case DioExceptionType.cancel:
        return const UnknownFailure(message: 'The request was cancelled.');
      case DioExceptionType.badCertificate:
        return const NetworkFailure(message: 'A secure connection could not be established.');
      case DioExceptionType.badResponse:
        return _fromStatusCode(exception);
      case DioExceptionType.unknown:
        return const UnknownFailure();
    }
  }

  static Failure _fromStatusCode(DioException exception) {
    final int? status = exception.response?.statusCode;
    final String? errorCode = _extractErrorCode(exception.response?.data);

    switch (status) {
      case 401:
        return AuthFailure(code: errorCode ?? 'UNAUTHENTICATED');
      case 403:
        return ForbiddenFailure(code: errorCode);
      case 404:
        return NotFoundFailure(code: errorCode);
      case 409:
        return ConflictFailure(code: errorCode);
      case 422:
        return ValidationFailure(
          code: errorCode ?? 'VALIDATION_ERROR',
          errors: _extractValidationErrors(exception.response?.data),
        );
      case 429:
        return RateLimitFailure(code: errorCode ?? 'RATE_LIMITED');
      default:
        if (status != null && status >= 500) {
          return ServerFailure(code: errorCode);
        }
        return UnknownFailure(code: errorCode);
    }
  }

  static String? _extractErrorCode(Object? responseData) {
    if (responseData is Map<String, dynamic>) {
      final Object? code = responseData['error_code'];
      if (code is String) {
        return code;
      }
    }
    return null;
  }

  static Map<String, List<String>>? _extractValidationErrors(Object? responseData) {
    if (responseData is Map<String, dynamic>) {
      final Object? errors = responseData['errors'];
      if (errors is Map<String, dynamic>) {
        return errors.map(
          (String key, Object? value) => MapEntry(
            key,
            value is List ? value.map((Object? e) => e.toString()).toList() : <String>[],
          ),
        );
      }
    }
    return null;
  }
}
