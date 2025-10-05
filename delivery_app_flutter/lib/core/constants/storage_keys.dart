/// 로컬 저장소 키 상수
class StorageKeys {
  // Secure Storage (민감한 정보)
  static const String accessToken = 'access_token';
  static const String refreshToken = 'refresh_token';
  static const String userPassword = 'user_password';

  // Shared Preferences (일반 정보)
  static const String userId = 'user_id';
  static const String userEmail = 'user_email';
  static const String userName = 'user_name';
  static const String userRole = 'user_role';
  static const String userPhone = 'user_phone';
  static const String isLoggedIn = 'is_logged_in';
  static const String rememberMe = 'remember_me';

  // App Settings
  static const String languageCode = 'language_code';
  static const String themeMode = 'theme_mode';
  static const String notificationsEnabled = 'notifications_enabled';

  // Cart
  static const String cartItems = 'cart_items';
  static const String lastCartUpdate = 'last_cart_update';

  // Address
  static const String defaultAddressId = 'default_address_id';
  static const String savedAddresses = 'saved_addresses';

  // Search History
  static const String searchHistory = 'search_history';

  // Onboarding
  static const String hasSeenOnboarding = 'has_seen_onboarding';
  static const String appFirstLaunch = 'app_first_launch';
}
