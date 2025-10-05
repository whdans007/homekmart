import 'package:json_annotation/json_annotation.dart';

part 'order_model.g.dart';

/// 주문 모델
@JsonSerializable()
class OrderModel {
  final int id;
  @JsonKey(name: 'order_number')
  final String orderNumber;
  @JsonKey(name: 'user_id')
  final int userId;
  @JsonKey(name: 'store_id')
  final int storeId;
  @JsonKey(name: 'delivery_address_id')
  final int deliveryAddressId;
  final double subtotal;
  @JsonKey(name: 'delivery_fee')
  final double deliveryFee;
  @JsonKey(name: 'points_used')
  final int pointsUsed;
  @JsonKey(name: 'total_amount')
  final double totalAmount;
  @JsonKey(name: 'payment_method')
  final String paymentMethod;
  @JsonKey(name: 'payment_status')
  final String paymentStatus;
  @JsonKey(name: 'order_status')
  final String orderStatus;
  @JsonKey(name: 'delivery_notes')
  final String? deliveryNotes;
  @JsonKey(name: 'cancel_reason')
  final String? cancelReason;
  @JsonKey(name: 'cancelled_at')
  final String? cancelledAt;
  @JsonKey(name: 'created_at')
  final String createdAt;
  @JsonKey(name: 'updated_at')
  final String updatedAt;

  // 주소 정보 (detail API에서만 포함)
  @JsonKey(name: 'address_name')
  final String? addressName;
  final String? city;
  final String? province;
  final String? barangay;
  final String? landmark;

  // 아이템 수 (list API에서만 포함)
  @JsonKey(name: 'item_count')
  final int? itemCount;

  OrderModel({
    required this.id,
    required this.orderNumber,
    required this.userId,
    required this.storeId,
    required this.deliveryAddressId,
    required this.subtotal,
    required this.deliveryFee,
    required this.pointsUsed,
    required this.totalAmount,
    required this.paymentMethod,
    required this.paymentStatus,
    required this.orderStatus,
    this.deliveryNotes,
    this.cancelReason,
    this.cancelledAt,
    required this.createdAt,
    required this.updatedAt,
    this.addressName,
    this.city,
    this.province,
    this.barangay,
    this.landmark,
    this.itemCount,
  });

  factory OrderModel.fromJson(Map<String, dynamic> json) =>
      _$OrderModelFromJson(json);

  Map<String, dynamic> toJson() => _$OrderModelToJson(this);

  /// Aliases for backward compatibility
  int get orderId => id;
  String get formattedOrderDate => createdAt; // TODO: Format to readable date
  String get formattedTotalAmount => formattedTotal;

  /// 금액 포맷팅
  String get formattedSubtotal => '₱${subtotal.toStringAsFixed(2)}';
  String get formattedDeliveryFee => '₱${deliveryFee.toStringAsFixed(2)}';
  String get formattedTotal => '₱${totalAmount.toStringAsFixed(2)}';

  /// 주문 상태 라벨
  String get statusLabel {
    switch (orderStatus) {
      case 'pending':
        return 'Pending';
      case 'confirmed':
        return 'Confirmed';
      case 'preparing':
        return 'Preparing';
      case 'out_for_delivery':
        return 'Out for Delivery';
      case 'delivered':
        return 'Delivered';
      case 'cancelled':
        return 'Cancelled';
      default:
        return orderStatus;
    }
  }

  /// 결제 방법 라벨
  String get paymentMethodLabel {
    switch (paymentMethod) {
      case 'cod':
        return 'Cash on Delivery';
      case 'gcash':
        return 'GCash';
      case 'paymaya':
        return 'PayMaya';
      default:
        return paymentMethod;
    }
  }

  /// 주문 취소 가능 여부
  bool get canCancel {
    return ['pending', 'confirmed', 'preparing'].contains(orderStatus);
  }

  /// 무료 배송 여부
  bool get isFreeDelivery => deliveryFee == 0;

  /// 포인트 사용 여부
  bool get hasUsedPoints => pointsUsed > 0;
}

/// 주문 상세 모델
@JsonSerializable()
class OrderDetailModel extends OrderModel {
  final List<OrderItemModel> items;
  final List<OrderTrackingModel> tracking;
  @JsonKey(name: 'house_number')
  final String? houseNumber;
  final String? street;
  @JsonKey(name: 'postal_code')
  final String? postalCode;
  @JsonKey(name: 'detailed_address')
  final String? detailedAddress;
  final double? latitude;
  final double? longitude;

  OrderDetailModel({
    required super.id,
    required super.orderNumber,
    required super.userId,
    required super.storeId,
    required super.deliveryAddressId,
    required super.subtotal,
    required super.deliveryFee,
    required super.pointsUsed,
    required super.totalAmount,
    required super.paymentMethod,
    required super.paymentStatus,
    required super.orderStatus,
    super.deliveryNotes,
    super.cancelReason,
    super.cancelledAt,
    required super.createdAt,
    required super.updatedAt,
    super.addressName,
    super.city,
    super.province,
    super.barangay,
    super.landmark,
    required this.items,
    required this.tracking,
    this.houseNumber,
    this.street,
    this.postalCode,
    this.detailedAddress,
    this.latitude,
    this.longitude,
  });

  factory OrderDetailModel.fromJson(Map<String, dynamic> json) =>
      _$OrderDetailModelFromJson(json);

  @override
  Map<String, dynamic> toJson() => _$OrderDetailModelToJson(this);

  /// 전체 주소
  String get fullAddress {
    final parts = <String>[];
    if (houseNumber != null) parts.add(houseNumber!);
    if (street != null) parts.add(street!);
    if (barangay != null) parts.add('Brgy. $barangay');
    if (city != null) parts.add(city!);
    if (province != null) parts.add(province!);
    return parts.join(', ');
  }

  /// GPS 좌표 있음 여부
  bool get hasCoordinates => latitude != null && longitude != null;

  /// Delivery address object (for backward compatibility)
  DeliveryAddressInfo get deliveryAddress => DeliveryAddressInfo(
    addressName: addressName,
    houseNumber: houseNumber,
    street: street,
    barangay: barangay,
    city: city,
    province: province,
    postalCode: postalCode,
    detailedAddress: detailedAddress,
    landmark: landmark,
    latitude: latitude,
    longitude: longitude,
  );
}

/// Helper class for delivery address
class DeliveryAddressInfo {
  final String? addressName;
  final String? houseNumber;
  final String? street;
  final String? barangay;
  final String? city;
  final String? province;
  final String? postalCode;
  final String? detailedAddress;
  final String? landmark;
  final double? latitude;
  final double? longitude;

  DeliveryAddressInfo({
    this.addressName,
    this.houseNumber,
    this.street,
    this.barangay,
    this.city,
    this.province,
    this.postalCode,
    this.detailedAddress,
    this.landmark,
    this.latitude,
    this.longitude,
  });

  String get fullAddress {
    final parts = <String>[];
    if (houseNumber != null) parts.add(houseNumber!);
    if (street != null) parts.add(street!);
    if (barangay != null) parts.add('Brgy. $barangay');
    if (city != null) parts.add(city!);
    if (province != null) parts.add(province!);
    return parts.join(', ');
  }
}

/// 주문 상품 모델
@JsonSerializable()
class OrderItemModel {
  final int id;
  @JsonKey(name: 'product_id')
  final int productId;
  @JsonKey(name: 'product_name')
  final String productName;
  final String? barcode;
  final int quantity;
  @JsonKey(name: 'unit_price')
  final double unitPrice;
  final double subtotal;

  OrderItemModel({
    required this.id,
    required this.productId,
    required this.productName,
    this.barcode,
    required this.quantity,
    required this.unitPrice,
    required this.subtotal,
  });

  factory OrderItemModel.fromJson(Map<String, dynamic> json) =>
      _$OrderItemModelFromJson(json);

  Map<String, dynamic> toJson() => _$OrderItemModelToJson(this);

  /// 가격 포맷팅
  String get formattedUnitPrice => '₱${unitPrice.toStringAsFixed(2)}';
  String get formattedSubtotal => '₱${subtotal.toStringAsFixed(2)}';
  String get formattedPrice => formattedUnitPrice; // Alias

  /// Image URL (not in API response, added for compatibility)
  String? get imageUrl => null; // TODO: Add image_url to API response
}

/// 주문 추적 모델
@JsonSerializable()
class OrderTrackingModel {
  final int id;
  final String status;
  final String notes;
  final String? location;
  @JsonKey(name: 'created_at')
  final String createdAt;
  @JsonKey(name: 'updated_by_name')
  final String? updatedByName;

  OrderTrackingModel({
    required this.id,
    required this.status,
    required this.notes,
    this.location,
    required this.createdAt,
    this.updatedByName,
  });

  factory OrderTrackingModel.fromJson(Map<String, dynamic> json) =>
      _$OrderTrackingModelFromJson(json);

  Map<String, dynamic> toJson() => _$OrderTrackingModelToJson(this);

  /// 상태 라벨
  String get statusLabel {
    switch (status) {
      case 'pending':
        return 'Order Placed';
      case 'confirmed':
        return 'Order Confirmed';
      case 'preparing':
        return 'Preparing Order';
      case 'out_for_delivery':
        return 'Out for Delivery';
      case 'delivered':
        return 'Delivered';
      case 'cancelled':
        return 'Cancelled';
      default:
        return status;
    }
  }

  /// Formatted timestamp (for backward compatibility)
  String get formattedTimestamp => createdAt; // TODO: Format to readable date
}

/// 주문 목록 응답
@JsonSerializable()
class OrderListResponse {
  final List<OrderModel> data;
  final PaginationModel pagination;

  OrderListResponse({
    required this.data,
    required this.pagination,
  });

  factory OrderListResponse.fromJson(Map<String, dynamic> json) =>
      _$OrderListResponseFromJson(json);

  Map<String, dynamic> toJson() => _$OrderListResponseToJson(this);

  /// Alias for backward compatibility
  List<OrderModel> get orders => data;
}

/// 페이지네이션 모델 (재사용)
@JsonSerializable()
class PaginationModel {
  @JsonKey(name: 'current_page')
  final int currentPage;
  @JsonKey(name: 'total_pages')
  final int totalPages;
  @JsonKey(name: 'total_items')
  final int totalItems;
  @JsonKey(name: 'items_per_page')
  final int itemsPerPage;
  @JsonKey(name: 'has_next')
  final bool hasNext;
  @JsonKey(name: 'has_prev')
  final bool hasPrev;

  PaginationModel({
    required this.currentPage,
    required this.totalPages,
    required this.totalItems,
    required this.itemsPerPage,
    required this.hasNext,
    required this.hasPrev,
  });

  factory PaginationModel.fromJson(Map<String, dynamic> json) =>
      _$PaginationModelFromJson(json);

  Map<String, dynamic> toJson() => _$PaginationModelToJson(this);
}
