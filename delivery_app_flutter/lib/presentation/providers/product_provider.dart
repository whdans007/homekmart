import 'package:flutter/material.dart';
import '../../data/models/product_model.dart';
import '../../data/services/product_service.dart';

/// 상품 목록 관리 Provider
class ProductProvider extends ChangeNotifier {
  final ProductService _productService;

  ProductProvider(this._productService);

  List<ProductModel> _products = [];
  List<ProductModel> get products => _products;

  bool _isLoading = false;
  bool get isLoading => _isLoading;

  String? _error;
  String? get error => _error;

  String _searchQuery = '';
  String get searchQuery => _searchQuery;

  int? _selectedCategoryId;
  int? get selectedCategoryId => _selectedCategoryId;

  /// 상품 목록 조회
  Future<void> loadProducts({
    int? storeId,
    int? categoryId,
    String? search,
  }) async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      final response = await _productService.getProducts(
        storeId: storeId ?? 1,
        categoryId: categoryId,
        search: search,
      );
      _products = response.products; // Use .products getter instead of .data
      _searchQuery = search ?? '';
      _selectedCategoryId = categoryId;
      _error = null;
    } catch (e) {
      _error = e.toString();
      _products = [];
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// 검색
  void setSearchQuery(String query) {
    _searchQuery = query;
    notifyListeners();
  }

  /// 카테고리 필터
  void setCategory(int? categoryId) {
    _selectedCategoryId = categoryId;
    notifyListeners();
  }

  /// 에러 초기화
  void clearError() {
    _error = null;
    notifyListeners();
  }
}
