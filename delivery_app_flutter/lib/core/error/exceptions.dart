/// 앱 전역 예외 클래스
class AppException implements Exception {
  final String message;
  final String? code;
  final dynamic details;

  AppException({
    required this.message,
    this.code,
    this.details,
  });

  @override
  String toString() => 'AppException: $message (Code: $code)';
}

/// 서버 예외
class ServerException extends AppException {
  ServerException({
    required super.message,
    super.code,
    super.details,
  });
}

/// 네트워크 예외
class NetworkException extends AppException {
  NetworkException({
    required super.message,
    super.code,
    super.details,
  });
}

/// 인증 예외
class AuthException extends AppException {
  AuthException({
    required super.message,
    super.code,
    super.details,
  });
}

/// 캐시 예외
class CacheException extends AppException {
  CacheException({
    required super.message,
    super.code,
    super.details,
  });
}

/// 유효성 검사 예외
class ValidationException extends AppException {
  ValidationException({
    required super.message,
    super.code,
    super.details,
  });
}

/// 권한 예외
class PermissionException extends AppException {
  PermissionException({
    required super.message,
    super.code,
    super.details,
  });
}
