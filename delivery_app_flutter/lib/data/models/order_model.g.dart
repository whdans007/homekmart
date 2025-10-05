// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'order_model.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

OrderModel _$OrderModelFromJson(Map<String, dynamic> json) => OrderModel(
      id: (json['id'] as num).toInt(),
      orderNumber: json['order_number'] as String,
      userId: (json['user_id'] as num).toInt(),
      storeId: (json['store_id'] as num).toInt(),
      deliveryAddressId: (json['delivery_address_id'] as num).toInt(),
      subtotal: (json['subtotal'] as num).toDouble(),
      deliveryFee: (json['delivery_fee'] as num).toDouble(),
      pointsUsed: (json['points_used'] as num).toInt(),
      totalAmount: (json['total_amount'] as num).toDouble(),
      paymentMethod: json['payment_method'] as String,
      paymentStatus: json['payment_status'] as String,
      orderStatus: json['order_status'] as String,
      deliveryNotes: json['delivery_notes'] as String?,
      cancelReason: json['cancel_reason'] as String?,
      cancelledAt: json['cancelled_at'] as String?,
      createdAt: json['created_at'] as String,
      updatedAt: json['updated_at'] as String,
      addressName: json['address_name'] as String?,
      city: json['city'] as String?,
      province: json['province'] as String?,
      barangay: json['barangay'] as String?,
      landmark: json['landmark'] as String?,
      itemCount: (json['item_count'] as num?)?.toInt(),
    );

Map<String, dynamic> _$OrderModelToJson(OrderModel instance) =>
    <String, dynamic>{
      'id': instance.id,
      'order_number': instance.orderNumber,
      'user_id': instance.userId,
      'store_id': instance.storeId,
      'delivery_address_id': instance.deliveryAddressId,
      'subtotal': instance.subtotal,
      'delivery_fee': instance.deliveryFee,
      'points_used': instance.pointsUsed,
      'total_amount': instance.totalAmount,
      'payment_method': instance.paymentMethod,
      'payment_status': instance.paymentStatus,
      'order_status': instance.orderStatus,
      'delivery_notes': instance.deliveryNotes,
      'cancel_reason': instance.cancelReason,
      'cancelled_at': instance.cancelledAt,
      'created_at': instance.createdAt,
      'updated_at': instance.updatedAt,
      'address_name': instance.addressName,
      'city': instance.city,
      'province': instance.province,
      'barangay': instance.barangay,
      'landmark': instance.landmark,
      'item_count': instance.itemCount,
    };

OrderDetailModel _$OrderDetailModelFromJson(Map<String, dynamic> json) =>
    OrderDetailModel(
      id: (json['id'] as num).toInt(),
      orderNumber: json['order_number'] as String,
      userId: (json['user_id'] as num).toInt(),
      storeId: (json['store_id'] as num).toInt(),
      deliveryAddressId: (json['delivery_address_id'] as num).toInt(),
      subtotal: (json['subtotal'] as num).toDouble(),
      deliveryFee: (json['delivery_fee'] as num).toDouble(),
      pointsUsed: (json['points_used'] as num).toInt(),
      totalAmount: (json['total_amount'] as num).toDouble(),
      paymentMethod: json['payment_method'] as String,
      paymentStatus: json['payment_status'] as String,
      orderStatus: json['order_status'] as String,
      deliveryNotes: json['delivery_notes'] as String?,
      cancelReason: json['cancel_reason'] as String?,
      cancelledAt: json['cancelled_at'] as String?,
      createdAt: json['created_at'] as String,
      updatedAt: json['updated_at'] as String,
      addressName: json['address_name'] as String?,
      city: json['city'] as String?,
      province: json['province'] as String?,
      barangay: json['barangay'] as String?,
      landmark: json['landmark'] as String?,
      items: (json['items'] as List<dynamic>)
          .map((e) => OrderItemModel.fromJson(e as Map<String, dynamic>))
          .toList(),
      tracking: (json['tracking'] as List<dynamic>)
          .map((e) => OrderTrackingModel.fromJson(e as Map<String, dynamic>))
          .toList(),
      houseNumber: json['house_number'] as String?,
      street: json['street'] as String?,
      postalCode: json['postal_code'] as String?,
      detailedAddress: json['detailed_address'] as String?,
      latitude: (json['latitude'] as num?)?.toDouble(),
      longitude: (json['longitude'] as num?)?.toDouble(),
    );

Map<String, dynamic> _$OrderDetailModelToJson(OrderDetailModel instance) =>
    <String, dynamic>{
      'id': instance.id,
      'order_number': instance.orderNumber,
      'user_id': instance.userId,
      'store_id': instance.storeId,
      'delivery_address_id': instance.deliveryAddressId,
      'subtotal': instance.subtotal,
      'delivery_fee': instance.deliveryFee,
      'points_used': instance.pointsUsed,
      'total_amount': instance.totalAmount,
      'payment_method': instance.paymentMethod,
      'payment_status': instance.paymentStatus,
      'order_status': instance.orderStatus,
      'delivery_notes': instance.deliveryNotes,
      'cancel_reason': instance.cancelReason,
      'cancelled_at': instance.cancelledAt,
      'created_at': instance.createdAt,
      'updated_at': instance.updatedAt,
      'address_name': instance.addressName,
      'city': instance.city,
      'province': instance.province,
      'barangay': instance.barangay,
      'landmark': instance.landmark,
      'items': instance.items,
      'tracking': instance.tracking,
      'house_number': instance.houseNumber,
      'street': instance.street,
      'postal_code': instance.postalCode,
      'detailed_address': instance.detailedAddress,
      'latitude': instance.latitude,
      'longitude': instance.longitude,
    };

OrderItemModel _$OrderItemModelFromJson(Map<String, dynamic> json) =>
    OrderItemModel(
      id: (json['id'] as num).toInt(),
      productId: (json['product_id'] as num).toInt(),
      productName: json['product_name'] as String,
      barcode: json['barcode'] as String?,
      quantity: (json['quantity'] as num).toInt(),
      unitPrice: (json['unit_price'] as num).toDouble(),
      subtotal: (json['subtotal'] as num).toDouble(),
    );

Map<String, dynamic> _$OrderItemModelToJson(OrderItemModel instance) =>
    <String, dynamic>{
      'id': instance.id,
      'product_id': instance.productId,
      'product_name': instance.productName,
      'barcode': instance.barcode,
      'quantity': instance.quantity,
      'unit_price': instance.unitPrice,
      'subtotal': instance.subtotal,
    };

OrderTrackingModel _$OrderTrackingModelFromJson(Map<String, dynamic> json) =>
    OrderTrackingModel(
      id: (json['id'] as num).toInt(),
      status: json['status'] as String,
      notes: json['notes'] as String,
      location: json['location'] as String?,
      createdAt: json['created_at'] as String,
      updatedByName: json['updated_by_name'] as String?,
    );

Map<String, dynamic> _$OrderTrackingModelToJson(OrderTrackingModel instance) =>
    <String, dynamic>{
      'id': instance.id,
      'status': instance.status,
      'notes': instance.notes,
      'location': instance.location,
      'created_at': instance.createdAt,
      'updated_by_name': instance.updatedByName,
    };

OrderListResponse _$OrderListResponseFromJson(Map<String, dynamic> json) =>
    OrderListResponse(
      data: (json['data'] as List<dynamic>)
          .map((e) => OrderModel.fromJson(e as Map<String, dynamic>))
          .toList(),
      pagination:
          PaginationModel.fromJson(json['pagination'] as Map<String, dynamic>),
    );

Map<String, dynamic> _$OrderListResponseToJson(OrderListResponse instance) =>
    <String, dynamic>{
      'data': instance.data,
      'pagination': instance.pagination,
    };

PaginationModel _$PaginationModelFromJson(Map<String, dynamic> json) =>
    PaginationModel(
      currentPage: (json['current_page'] as num).toInt(),
      totalPages: (json['total_pages'] as num).toInt(),
      totalItems: (json['total_items'] as num).toInt(),
      itemsPerPage: (json['items_per_page'] as num).toInt(),
      hasNext: json['has_next'] as bool,
      hasPrev: json['has_prev'] as bool,
    );

Map<String, dynamic> _$PaginationModelToJson(PaginationModel instance) =>
    <String, dynamic>{
      'current_page': instance.currentPage,
      'total_pages': instance.totalPages,
      'total_items': instance.totalItems,
      'items_per_page': instance.itemsPerPage,
      'has_next': instance.hasNext,
      'has_prev': instance.hasPrev,
    };
