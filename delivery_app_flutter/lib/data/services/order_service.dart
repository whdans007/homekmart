import '../../core/network/dio_client.dart';
import '../../core/constants/api_constants.dart';
import '../../core/constants/app_constants.dart';
import '../../core/error/exceptions.dart';
import '../models/order_model.dart';

/// 주문 서비스
class OrderService {
  final DioClient _dioClient;

  OrderService(this._dioClient);

  /// 주문 생성
  Future<Map<String, dynamic>> createOrder({
    required int deliveryAddressId,
    required String paymentMethod,
    String? deliveryNotes,
    int usePoints = 0,
  }) async {
    try {
      final response = await _dioClient.post(
        ApiConstants.orderCreate,
        data: {
          'delivery_address_id': deliveryAddressId,
          'payment_method': paymentMethod,
          if (deliveryNotes != null) 'delivery_notes': deliveryNotes,
          'use_points': usePoints,
        },
      );

      if (response.data['success'] == true) {
        return response.data['data'];
      } else {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Failed to create order',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error while creating order');
    }
  }

  /// 주문 목록 조회
  Future<OrderListResponse> getOrders({
    int page = 1,
    int limit = AppConstants.defaultPageSize,
    String? status,
  }) async {
    try {
      final queryParams = {
        'page': page,
        'limit': limit,
        if (status != null && status.isNotEmpty) 'status': status,
      };

      final response = await _dioClient.get(
        ApiConstants.orders,
        queryParameters: queryParams,
      );

      if (response.data['success'] == true) {
        return OrderListResponse.fromJson(response.data['data']);
      } else {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Failed to fetch orders',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error while fetching orders');
    }
  }

  /// 주문 상세 조회
  Future<OrderDetailModel> getOrderDetail({required int orderId}) async {
    try {
      final response = await _dioClient.get(
        '${ApiConstants.orderDetail}?id=$orderId',
      );

      if (response.data['success'] == true) {
        return OrderDetailModel.fromJson(response.data['data']);
      } else {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Failed to fetch order',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error while fetching order detail');
    }
  }

  /// 주문 취소
  Future<Map<String, dynamic>> cancelOrder({
    required int orderId,
    String? cancelReason,
  }) async {
    try {
      final response = await _dioClient.post(
        '${ApiConstants.orderCancel}?id=$orderId',
        data: {
          if (cancelReason != null) 'cancel_reason': cancelReason,
        },
      );

      if (response.data['success'] == true) {
        return response.data['data'];
      } else {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Failed to cancel order',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error while canceling order');
    }
  }

  /// 주문 추적 조회
  Future<Map<String, dynamic>> getOrderTracking({required int orderId}) async {
    try {
      final response = await _dioClient.get(
        '${ApiConstants.orderTracking}?id=$orderId',
      );

      if (response.data['success'] == true) {
        return response.data['data'];
      } else {
        throw ServerException(
          message: response.data['error']?['message'] ?? 'Failed to fetch tracking',
        );
      }
    } catch (e) {
      if (e is ServerException) rethrow;
      throw NetworkException(message: 'Network error while fetching tracking');
    }
  }
}
