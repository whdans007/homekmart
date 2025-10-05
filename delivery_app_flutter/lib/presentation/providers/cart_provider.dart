import 'package:flutter/foundation.dart';
import '../../core/network/dio_client.dart';
import '../../data/services/cart_service.dart';
import '../../data/models/cart_model.dart';
import '../../core/error/exceptions.dart';

/// 장바구니 상태 관리
class CartProvider extends ChangeNotifier {
  final CartService _cartService;

  List<CartItemModel> _items = [];
  CartSummary _summary = CartSummary.empty();
  bool _isLoading = false;
  String? _error;

  CartProvider(DioClient dioClient) : _cartService = CartService(dioClient);

  // Getters
  List<CartItemModel> get items => _items;
  CartSummary get summary => _summary;
  bool get isLoading => _isLoading;
  String? get error => _error;
  bool get isEmpty => _items.isEmpty;
  int get itemCount => _items.length;

  /// 장바구니 불러오기
  Future<void> loadCart() async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      final response = await _cartService.getCart();
      _items = response.items;
      _summary = response.summary;
    } on ServerException catch (e) {
      _error = e.message;
    } on NetworkException catch (e) {
      _error = e.message;
    } catch (e) {
      _error = 'Failed to load cart: ${e.toString()}';
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// 장바구니에 상품 추가
  Future<bool> addToCart({
    required int productId,
    required int storeId,
    int quantity = 1,
  }) async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      await _cartService.addToCart(
        productId: productId,
        storeId: storeId,
        quantity: quantity,
      );

      // 장바구니 새로고침
      await loadCart();
      return true;
    } on ServerException catch (e) {
      _error = e.message;
      _isLoading = false;
      notifyListeners();
      return false;
    } on NetworkException catch (e) {
      _error = e.message;
      _isLoading = false;
      notifyListeners();
      return false;
    } catch (e) {
      _error = 'Failed to add to cart: ${e.toString()}';
      _isLoading = false;
      notifyListeners();
      return false;
    }
  }

  /// 장바구니 수량 변경
  Future<bool> updateQuantity({
    required int cartId,
    required int quantity,
  }) async {
    if (quantity <= 0) {
      return await removeItem(cartId: cartId);
    }

    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      await _cartService.updateCartItem(
        cartId: cartId,
        quantity: quantity,
      );

      // 장바구니 새로고침
      await loadCart();
      return true;
    } on ServerException catch (e) {
      _error = e.message;
      _isLoading = false;
      notifyListeners();
      return false;
    } on NetworkException catch (e) {
      _error = e.message;
      _isLoading = false;
      notifyListeners();
      return false;
    } catch (e) {
      _error = 'Failed to update quantity: ${e.toString()}';
      _isLoading = false;
      notifyListeners();
      return false;
    }
  }

  /// 장바구니 항목 삭제
  Future<bool> removeItem({required int cartId}) async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      await _cartService.deleteCartItem(cartId: cartId);

      // 장바구니 새로고침
      await loadCart();
      return true;
    } on ServerException catch (e) {
      _error = e.message;
      _isLoading = false;
      notifyListeners();
      return false;
    } on NetworkException catch (e) {
      _error = e.message;
      _isLoading = false;
      notifyListeners();
      return false;
    } catch (e) {
      _error = 'Failed to remove item: ${e.toString()}';
      _isLoading = false;
      notifyListeners();
      return false;
    }
  }

  /// 장바구니 비우기
  Future<bool> clearCart() async {
    if (_items.isEmpty) return true;

    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      final cartIds = _items.map((item) => item.cartId).toList();
      await _cartService.clearCart(cartIds);

      _items = [];
      _summary = CartSummary.empty();
      return true;
    } on ServerException catch (e) {
      _error = e.message;
      return false;
    } on NetworkException catch (e) {
      _error = e.message;
      return false;
    } catch (e) {
      _error = 'Failed to clear cart: ${e.toString()}';
      return false;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// 재고 부족 상품 확인
  bool get hasOutOfStockItems {
    return _items.any((item) => !item.canOrder);
  }

  /// 재고 부족 상품 목록
  List<CartItemModel> get outOfStockItems {
    return _items.where((item) => !item.canOrder).toList();
  }

  /// 에러 초기화
  void clearError() {
    _error = null;
    notifyListeners();
  }
}
