import 'package:json_annotation/json_annotation.dart';

part 'product_model.g.dart';

/// 상품 모델
@JsonSerializable()
class ProductModel {
  final int id;
  @JsonKey(name: 'name_ko')
  final String? nameKo;
  @JsonKey(name: 'name_en')
  final String? nameEn;
  final String? sku;
  final String? description;
  @JsonKey(name: 'category_id')
  final int? categoryId;
  @JsonKey(name: 'category_name')
  final String? categoryName;
  @JsonKey(name: 'brand_id')
  final int? brandId;
  @JsonKey(name: 'brand_name')
  final String? brandName;
  @JsonKey(name: 'selling_price')
  final double sellingPrice;
  @JsonKey(name: 'cost_price')
  final double? costPrice;
  final int quantity;
  @JsonKey(name: 'image_url')
  final String? imageUrl;
  @JsonKey(name: 'is_active')
  final bool isActive;
  @JsonKey(name: 'created_at')
  final String? createdAt;
  @JsonKey(name: 'updated_at')
  final String? updatedAt;

  ProductModel({
    required this.id,
    this.nameKo,
    this.nameEn,
    this.sku,
    this.description,
    this.categoryId,
    this.categoryName,
    this.brandId,
    this.brandName,
    required this.sellingPrice,
    this.costPrice,
    required this.quantity,
    this.imageUrl,
    this.isActive = true,
    this.createdAt,
    this.updatedAt,
  });

  factory ProductModel.fromJson(Map<String, dynamic> json) =>
      _$ProductModelFromJson(json);

  Map<String, dynamic> toJson() => _$ProductModelToJson(this);

  /// Aliases for backward compatibility
  int get productId => id;
  int get stockQuantity => quantity;

  /// 이름 (한국어 우선, 없으면 영어)
  String get name => nameKo?.isNotEmpty == true ? nameKo! : (nameEn ?? 'No name');

  /// 재고 있음 여부
  bool get isInStock => quantity > 0;

  /// 재고 부족 여부 (10개 이하)
  bool get isLowStock => quantity > 0 && quantity <= 10;

  /// 가격 포맷팅 (₱ 기호 포함)
  String get formattedPrice => '₱${sellingPrice.toStringAsFixed(2)}';

  /// 할인율 계산 (있을 경우)
  double? get discountPercentage {
    if (costPrice != null && costPrice! > 0) {
      return ((sellingPrice - costPrice!) / costPrice!) * 100;
    }
    return null;
  }
}

/// 상품 목록 응답
@JsonSerializable()
class ProductListResponse {
  final List<ProductModel> data;
  final PaginationModel pagination;

  ProductListResponse({
    required this.data,
    required this.pagination,
  });

  factory ProductListResponse.fromJson(Map<String, dynamic> json) =>
      _$ProductListResponseFromJson(json);

  Map<String, dynamic> toJson() => _$ProductListResponseToJson(this);

  /// Alias for backward compatibility
  List<ProductModel> get products => data;
}

/// 페이지네이션 모델
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
