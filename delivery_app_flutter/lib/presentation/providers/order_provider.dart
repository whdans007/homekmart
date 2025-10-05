import 'package:flutter/material.dart';
import '../../data/models/order_model.dart';
import '../../data/services/order_service.dart';

/// 주문 관리 Provider
class OrderProvider extends ChangeNotifier {
  final OrderService _orderService;

  OrderProvider(this._orderService);

  List<OrderModel> _orders = [];
  List<OrderModel> get orders => _orders;

  OrderDetailModel? _currentOrderDetail;
  OrderDetailModel? get currentOrderDetail => _currentOrderDetail;

  List<OrderTrackingModel> _tracking = [];
  List<OrderTrackingModel> get tracking => _tracking;

  bool _isLoading = false;
  bool get isLoading => _isLoading;

  String? _error;
  String? get error => _error;

  /// 주문 목록 조회
  Future<void> loadOrders({String? status}) async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      final response = await _orderService.getOrders(status: status);
      _orders = response.orders; // Use .orders getter instead of direct assignment
      _error = null;
    } catch (e) {
      _error = e.toString();
      _orders = [];
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// 주문 상세 조회
  Future<void> loadOrderDetail(int orderId) async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      _currentOrderDetail = await _orderService.getOrderDetail(orderId: orderId);
      _error = null;
    } catch (e) {
      _error = e.toString();
      _currentOrderDetail = null;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// 주문 추적 정보 조회
  Future<void> loadOrderTracking(int orderId) async {
    try {
      final response = await _orderService.getOrderTracking(orderId: orderId);
      final trackingList = response['tracking'] as List<dynamic>?;
      _tracking = trackingList?.map((item) => OrderTrackingModel.fromJson(item as Map<String, dynamic>)).toList() ?? [];
      notifyListeners();
    } catch (e) {
      _error = e.toString();
      _tracking = [];
      notifyListeners();
    }
  }

  /// 주문 생성
  Future<bool> createOrder({
    required int addressId,
    required String paymentMethod,
    String? notes,
  }) async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      await _orderService.createOrder(
        deliveryAddressId: addressId,
        paymentMethod: paymentMethod,
        deliveryNotes: notes,
      );
      _isLoading = false;
      notifyListeners();
      return true;
    } catch (e) {
      _error = e.toString();
      _isLoading = false;
      notifyListeners();
      return false;
    }
  }

  /// 주문 취소
  Future<bool> cancelOrder(int orderId) async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      await _orderService.cancelOrder(orderId: orderId);
      // 주문 목록 새로고침
      await loadOrders();
      return true;
    } catch (e) {
      _error = e.toString();
      _isLoading = false;
      notifyListeners();
      return false;
    }
  }

  /// 에러 초기화
  void clearError() {
    _error = null;
    notifyListeners();
  }
}
