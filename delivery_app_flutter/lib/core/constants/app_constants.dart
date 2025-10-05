/// 앱 전역 상수
class AppConstants {
  // App Info
  static const String appName = 'HOME K MART Delivery';
  static const String appVersion = '1.0.0';

  // Pagination
  static const int defaultPageSize = 20;
  static const int maxPageSize = 100;

  // Store
  static const int defaultStoreId = 1;

  // Payment Methods
  static const String paymentCOD = 'cod';
  static const String paymentGCash = 'gcash';
  static const String paymentPayMaya = 'paymaya';

  static const List<String> paymentMethods = [
    paymentCOD,
    paymentGCash,
    paymentPayMaya,
  ];

  static const Map<String, String> paymentMethodLabels = {
    paymentCOD: 'Cash on Delivery',
    paymentGCash: 'GCash',
    paymentPayMaya: 'PayMaya',
  };

  // Order Status
  static const String orderPending = 'pending';
  static const String orderConfirmed = 'confirmed';
  static const String orderPreparing = 'preparing';
  static const String orderOutForDelivery = 'out_for_delivery';
  static const String orderDelivered = 'delivered';
  static const String orderCancelled = 'cancelled';

  static const Map<String, String> orderStatusLabels = {
    orderPending: 'Pending',
    orderConfirmed: 'Confirmed',
    orderPreparing: 'Preparing',
    orderOutForDelivery: 'Out for Delivery',
    orderDelivered: 'Delivered',
    orderCancelled: 'Cancelled',
  };

  // Currency
  static const String currencySymbol = '₱';
  static const String currencyCode = 'PHP';

  // Images
  static const String placeholderImage = 'assets/images/placeholder.png';
  static const String logoImage = 'assets/logos/logo.png';

  // Regex Patterns
  static const String emailPattern =
      r'^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$';
  static const String phonePattern = r'^\+?63[0-9]{10}$';

  // Min/Max Values
  static const int minPasswordLength = 6;
  static const int maxCartQuantity = 99;
  static const double minOrderAmount = 0;
  static const double freeDeliveryThreshold = 1000.0;
  static const double defaultDeliveryFee = 50.0;
}
