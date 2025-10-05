import '../../core/network/dio_client.dart';
import '../../core/constants/api_constants.dart';
import '../../core/constants/app_constants.dart';
import '../../core/error/exceptions.dart';
import '../models/product_model.dart';

/// 상품 서비스
class ProductService {
  final DioClient _dioClient;

  ProductService(this._dioClient);

  /// 상품 목록 조회
  Future<ProductListResponse> getProducts({
    int storeId = AppConstants.defaultStoreId,
    int page = 1,
    int limit = AppConstants.defaultPageSize,
    int? categoryId,
    String? search,
  }) async {
    try {
      final queryParams = {
        'store_id': storeId,
        'page': page,
        'limit': limit,
        if (categoryId != null) 'category_id': categoryId,
        if (search != null && search.isNotEmpty) 'search': search,
      };

      final response = await _dioClient.get(
        ApiConstants.products,
        queryParameters: queryParams,
      );

      if (response.data['success'] == true) {
        return ProductListResponse.fromJson(response.data['data']);
      } else {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Failed to fetch products',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error while fetching products');
    }
  }

  /// 상품 상세 조회
  Future<ProductModel> getProductDetail({
    required int productId,
    int storeId = AppConstants.defaultStoreId,
  }) async {
    try {
      final response = await _dioClient.get(
        ApiConstants.productDetail,
        queryParameters: {
          'id': productId,
          'store_id': storeId,
        },
      );

      if (response.data['success'] == true) {
        return ProductModel.fromJson(response.data['data']);
      } else {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Failed to fetch product',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error while fetching product detail');
    }
  }
}
