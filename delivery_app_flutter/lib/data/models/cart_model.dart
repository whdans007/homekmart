import 'package:json_annotation/json_annotation.dart';

part 'cart_model.g.dart';

/// 장바구니 아이템 모델
@JsonSerializable()
class CartItemModel {
  @JsonKey(name: 'cart_id')
  final int cartId;
  @JsonKey(name: 'product_id')
  final int productId;
  @JsonKey(name: 'product_name')
  final String productName;
  final String? barcode;
  @JsonKey(name: 'unit_price')
  final double unitPrice;
  final int quantity;
  final double subtotal;
  @JsonKey(name: 'stock_quantity')
  final int stockQuantity;
  @JsonKey(name: 'image_url')
  final String? imageUrl;

  CartItemModel({
    required this.cartId,
    required this.productId,
    required this.productName,
    this.barcode,
    required this.unitPrice,
    required this.quantity,
    required this.subtotal,
    required this.stockQuantity,
    this.imageUrl,
  });

  factory CartItemModel.fromJson(Map<String, dynamic> json) =>
      _$CartItemModelFromJson(json);

  Map<String, dynamic> toJson() => _$CartItemModelToJson(this);

  /// 재고 있음 여부
  bool get isInStock => stockQuantity > 0;

  /// 재고 부족 여부
  bool get isOutOfStock => stockQuantity <= 0;

  /// 주문 가능 여부 (재고가 요청 수량보다 많거나 같음)
  bool get canOrder => stockQuantity >= quantity;

  /// 가격 포맷팅
  String get formattedUnitPrice => '₱${unitPrice.toStringAsFixed(2)}';
  String get formattedSubtotal => '₱${subtotal.toStringAsFixed(2)}';
  String get formattedPrice => formattedUnitPrice; // Alias

  /// 복사 메서드
  CartItemModel copyWith({
    int? cartId,
    int? productId,
    String? productName,
    String? barcode,
    double? unitPrice,
    int? quantity,
    double? subtotal,
    int? stockQuantity,
    String? imageUrl,
  }) {
    return CartItemModel(
      cartId: cartId ?? this.cartId,
      productId: productId ?? this.productId,
      productName: productName ?? this.productName,
      barcode: barcode ?? this.barcode,
      unitPrice: unitPrice ?? this.unitPrice,
      quantity: quantity ?? this.quantity,
      subtotal: subtotal ?? this.subtotal,
      stockQuantity: stockQuantity ?? this.stockQuantity,
      imageUrl: imageUrl ?? this.imageUrl,
    );
  }
}

/// 장바구니 응답 모델
@JsonSerializable()
class CartResponse {
  final List<CartItemModel> items;
  final CartSummary summary;

  CartResponse({
    required this.items,
    required this.summary,
  });

  factory CartResponse.fromJson(Map<String, dynamic> json) =>
      _$CartResponseFromJson(json);

  Map<String, dynamic> toJson() => _$CartResponseToJson(this);

  /// 빈 장바구니 여부
  bool get isEmpty => items.isEmpty;

  /// 재고 부족 상품 확인
  bool get hasOutOfStockItems =>
      items.any((item) => !item.canOrder);

  /// 재고 부족 상품 목록
  List<CartItemModel> get outOfStockItems =>
      items.where((item) => !item.canOrder).toList();
}

/// 장바구니 요약 정보
@JsonSerializable()
class CartSummary {
  @JsonKey(name: 'total_items')
  final int totalItems;
  @JsonKey(name: 'total_quantity')
  final int totalQuantity;
  final double subtotal;

  CartSummary({
    required this.totalItems,
    required this.totalQuantity,
    required this.subtotal,
  });

  factory CartSummary.fromJson(Map<String, dynamic> json) =>
      _$CartSummaryFromJson(json);

  Map<String, dynamic> toJson() => _$CartSummaryToJson(this);

  /// 가격 포맷팅
  String get formattedSubtotal => '₱${subtotal.toStringAsFixed(2)}';
  String get formattedTotal => formattedSubtotal; // Alias
  String get formattedDeliveryFee => '₱0.00'; // Default, will be calculated in checkout

  /// 빈 요약 생성
  factory CartSummary.empty() {
    return CartSummary(
      totalItems: 0,
      totalQuantity: 0,
      subtotal: 0.0,
    );
  }
}
