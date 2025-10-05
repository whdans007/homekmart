import '../../core/network/dio_client.dart';
import '../../core/constants/api_constants.dart';
import '../../core/error/exceptions.dart';
import '../models/cart_model.dart';

/// 장바구니 서비스
class CartService {
  final DioClient _dioClient;

  CartService(this._dioClient);

  /// 장바구니 조회
  Future<CartResponse> getCart() async {
    try {
      final response = await _dioClient.get(ApiConstants.cart);

      if (response.data['success'] == true) {
        return CartResponse.fromJson(response.data['data']);
      } else {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Failed to fetch cart',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error while fetching cart');
    }
  }

  /// 장바구니에 상품 추가
  Future<Map<String, dynamic>> addToCart({
    required int productId,
    required int storeId,
    required int quantity,
  }) async {
    try {
      final response = await _dioClient.post(
        ApiConstants.cartAdd,
        data: {
          'product_id': productId,
          'store_id': storeId,
          'quantity': quantity,
        },
      );

      if (response.data['success'] == true) {
        return response.data['data'];
      } else {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Failed to add to cart',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error while adding to cart');
    }
  }

  /// 장바구니 수량 변경
  Future<Map<String, dynamic>> updateCartItem({
    required int cartId,
    required int quantity,
  }) async {
    try {
      final response = await _dioClient.put(
        '${ApiConstants.cartUpdate}?id=$cartId',
        data: {'quantity': quantity},
      );

      if (response.data['success'] == true) {
        return response.data['data'];
      } else {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Failed to update cart',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error while updating cart');
    }
  }

  /// 장바구니 항목 삭제
  Future<void> deleteCartItem({required int cartId}) async {
    try {
      final response = await _dioClient.delete(
        '${ApiConstants.cartDelete}?id=$cartId',
      );

      if (response.data['success'] != true) {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Failed to delete cart item',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error while deleting cart item');
    }
  }

  /// 장바구니 전체 삭제
  Future<void> clearCart(List<int> cartIds) async {
    try {
      // 모든 항목을 개별적으로 삭제
      for (final cartId in cartIds) {
        await deleteCartItem(cartId: cartId);
      }
    } catch (e) {
      rethrow;
    }
  }
}
