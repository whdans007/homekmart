/// API 상수 정의
class ApiConstants {
  // Base URL
  static const String baseUrl =
      'https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api';

  // Auth Endpoints
  static const String register = '/auth/register';
  static const String login = '/auth/login';
  static const String profile = '/auth/profile';

  // Product Endpoints
  static const String products = '/products';
  static const String productDetail = '/products/detail';

  // Address Endpoints
  static const String addresses = '/addresses';
  static const String addressCreate = '/addresses';
  static const String addressUpdate = '/addresses';
  static const String addressDelete = '/addresses';
  static const String addressSetDefault = '/addresses/set-default';

  // Cart Endpoints
  static const String cart = '/cart';
  static const String cartAdd = '/cart';
  static const String cartUpdate = '/cart';
  static const String cartDelete = '/cart';

  // Order Endpoints
  static const String orders = '/orders';
  static const String orderCreate = '/orders';
  static const String orderDetail = '/orders/detail';
  static const String orderCancel = '/orders/cancel';
  static const String orderTracking = '/orders/tracking';

  // Delivery Zone Endpoints
  static const String deliveryZones = '/delivery-zones';
  static const String deliveryZoneCheck = '/delivery-zones/check';
  static const String deliveryZoneFee = '/delivery-zones/calculate-fee';

  // Headers
  static const Map<String, String> headers = {
    'Content-Type': 'application/json; charset=UTF-8',
    'Accept': 'application/json',
  };

  // Timeouts
  static const Duration connectTimeout = Duration(seconds: 30);
  static const Duration receiveTimeout = Duration(seconds: 30);
  static const Duration sendTimeout = Duration(seconds: 30);
}
