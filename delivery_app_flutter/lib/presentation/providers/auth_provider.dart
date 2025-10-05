import 'package:flutter/foundation.dart';
import '../../core/network/dio_client.dart';
import '../../data/services/auth_service.dart';
import '../../data/models/user_model.dart';
import '../../core/error/exceptions.dart';

/// 인증 상태 관리
class AuthProvider extends ChangeNotifier {
  final AuthService _authService;

  UserModel? _user;
  bool _isLoggedIn = false;
  bool _isLoading = false;
  String? _error;

  AuthProvider(DioClient dioClient) : _authService = AuthService(dioClient);

  // Getters
  UserModel? get user => _user;
  bool get isLoggedIn => _isLoggedIn;
  bool get isLoading => _isLoading;
  String? get error => _error;

  /// 초기화 - 로그인 상태 확인
  Future<void> initialize() async {
    _isLoading = true;
    notifyListeners();

    try {
      final isLoggedIn = await _authService.isLoggedIn();

      if (isLoggedIn) {
        _user = await _authService.getProfile();
        _isLoggedIn = true;
      }
    } catch (e) {
      _error = 'Failed to initialize: ${e.toString()}';
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// 로그인
  Future<bool> login({
    required String email,
    required String password,
  }) async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      final data = await _authService.login(
        email: email,
        password: password,
      );

      _user = UserModel.fromJson(data['user']);
      _isLoggedIn = true;
      _error = null;

      notifyListeners();
      return true;
    } on AuthException catch (e) {
      _error = e.message;
      _isLoggedIn = false;
      notifyListeners();
      return false;
    } on NetworkException catch (e) {
      _error = e.message;
      notifyListeners();
      return false;
    } catch (e) {
      _error = 'Login failed: ${e.toString()}';
      notifyListeners();
      return false;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// 회원가입
  Future<bool> register({
    required String email,
    required String password,
    required String fullName,
    required String phone,
    String? username,
  }) async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      final data = await _authService.register(
        email: email,
        password: password,
        fullName: fullName,
        phone: phone,
        username: username,
      );

      _user = UserModel.fromJson(data['user']);
      _isLoggedIn = true;
      _error = null;

      notifyListeners();
      return true;
    } on ServerException catch (e) {
      _error = e.message;
      notifyListeners();
      return false;
    } on NetworkException catch (e) {
      _error = e.message;
      notifyListeners();
      return false;
    } catch (e) {
      _error = 'Registration failed: ${e.toString()}';
      notifyListeners();
      return false;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// 프로필 새로고침
  Future<void> refreshProfile() async {
    if (!_isLoggedIn) return;

    try {
      _user = await _authService.getProfile();
      notifyListeners();
    } catch (e) {
      _error = 'Failed to refresh profile: ${e.toString()}';
      notifyListeners();
    }
  }

  /// 로그아웃
  Future<void> logout() async {
    _isLoading = true;
    notifyListeners();

    try {
      await _authService.logout();
      _user = null;
      _isLoggedIn = false;
      _error = null;
    } catch (e) {
      _error = 'Logout failed: ${e.toString()}';
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// 에러 초기화
  void clearError() {
    _error = null;
    notifyListeners();
  }
}
