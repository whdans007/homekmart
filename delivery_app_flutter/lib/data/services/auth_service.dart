import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../../core/network/dio_client.dart';
import '../../core/constants/api_constants.dart';
import '../../core/constants/storage_keys.dart';
import '../../core/error/exceptions.dart';
import '../models/user_model.dart';

/// 인증 서비스
class AuthService {
  final DioClient _dioClient;
  final FlutterSecureStorage _secureStorage = const FlutterSecureStorage();

  AuthService(this._dioClient);

  /// 회원가입
  Future<Map<String, dynamic>> register({
    required String email,
    required String password,
    required String fullName,
    required String phone,
    String? username,
  }) async {
    try {
      final response = await _dioClient.post(
        ApiConstants.register,
        data: {
          'email': email,
          'password': password,
          'full_name': fullName,
          'phone': phone,
          if (username != null) 'username': username,
        },
      );

      if (response.data['success'] == true) {
        final data = response.data['data'];

        // JWT 토큰 저장
        await _saveToken(data['token']);

        return data;
      } else {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Registration failed',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error during registration');
    }
  }

  /// 로그인
  Future<Map<String, dynamic>> login({
    required String email,
    required String password,
  }) async {
    try {
      final response = await _dioClient.post(
        ApiConstants.login,
        data: {
          'email': email,
          'password': password,
        },
      );

      if (response.data['success'] == true) {
        final data = response.data['data'];

        // JWT 토큰 저장
        await _saveToken(data['token']);

        return data;
      } else {
        throw AuthException(
          message: response.data['error']?['message'] ?? 'Login failed',
        );
      }
    } catch (e) {
      if (e is AuthException) rethrow;
      throw NetworkException(message: 'Network error during login');
    }
  }

  /// 프로필 조회
  Future<UserModel> getProfile() async {
    try {
      final response = await _dioClient.get(ApiConstants.profile);

      if (response.data['success'] == true) {
        return UserModel.fromJson(response.data['data']);
      } else {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Failed to get profile',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error while fetching profile');
    }
  }

  /// 로그아웃
  Future<void> logout() async {
    try {
      // 토큰 삭제
      await _secureStorage.delete(key: StorageKeys.accessToken);
      await _secureStorage.delete(key: StorageKeys.refreshToken);

      // 사용자 정보 삭제
      await _secureStorage.deleteAll();
    } catch (e) {
      throw CacheException(message: 'Failed to logout');
    }
  }

  /// JWT 토큰 저장
  Future<void> _saveToken(String token) async {
    try {
      await _secureStorage.write(
        key: StorageKeys.accessToken,
        value: token,
      );
    } catch (e) {
      throw CacheException(message: 'Failed to save token');
    }
  }

  /// JWT 토큰 가져오기
  Future<String?> getToken() async {
    try {
      return await _secureStorage.read(key: StorageKeys.accessToken);
    } catch (e) {
      return null;
    }
  }

  /// 로그인 상태 확인
  Future<bool> isLoggedIn() async {
    final token = await getToken();
    return token != null && token.isNotEmpty;
  }
}
