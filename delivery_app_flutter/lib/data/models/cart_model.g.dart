// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'cart_model.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

CartItemModel _$CartItemModelFromJson(Map<String, dynamic> json) =>
    CartItemModel(
      cartId: (json['cart_id'] as num).toInt(),
      productId: (json['product_id'] as num).toInt(),
      productName: json['product_name'] as String,
      barcode: json['barcode'] as String?,
      unitPrice: (json['unit_price'] as num).toDouble(),
      quantity: (json['quantity'] as num).toInt(),
      subtotal: (json['subtotal'] as num).toDouble(),
      stockQuantity: (json['stock_quantity'] as num).toInt(),
      imageUrl: json['image_url'] as String?,
    );

Map<String, dynamic> _$CartItemModelToJson(CartItemModel instance) =>
    <String, dynamic>{
      'cart_id': instance.cartId,
      'product_id': instance.productId,
      'product_name': instance.productName,
      'barcode': instance.barcode,
      'unit_price': instance.unitPrice,
      'quantity': instance.quantity,
      'subtotal': instance.subtotal,
      'stock_quantity': instance.stockQuantity,
      'image_url': instance.imageUrl,
    };

CartResponse _$CartResponseFromJson(Map<String, dynamic> json) => CartResponse(
      items: (json['items'] as List<dynamic>)
          .map((e) => CartItemModel.fromJson(e as Map<String, dynamic>))
          .toList(),
      summary: CartSummary.fromJson(json['summary'] as Map<String, dynamic>),
    );

Map<String, dynamic> _$CartResponseToJson(CartResponse instance) =>
    <String, dynamic>{
      'items': instance.items,
      'summary': instance.summary,
    };

CartSummary _$CartSummaryFromJson(Map<String, dynamic> json) => CartSummary(
      totalItems: (json['total_items'] as num).toInt(),
      totalQuantity: (json['total_quantity'] as num).toInt(),
      subtotal: (json['subtotal'] as num).toDouble(),
    );

Map<String, dynamic> _$CartSummaryToJson(CartSummary instance) =>
    <String, dynamic>{
      'total_items': instance.totalItems,
      'total_quantity': instance.totalQuantity,
      'subtotal': instance.subtotal,
    };
